<?php
/**
 * Integration tests for Item_Resolver.
 *
 * Lives under tests/integration/ because it exercises real WordPress
 * post/postmeta state (not pure functions). Requires the WordPress test
 * suite — these are skipped automatically in unit-only runs.
 *
 * Items live under post types named tnc_col_<id>_item in Tainacan; we
 * register a fake post type here so the dedup queries find the seed
 * records without needing a full Tainacan_OAI_PMH\Activator schema.
 *
 * @package Tainacan_OAI_PMH
 */

use Tainacan_OAI_PMH\Item_Resolver;

/**
 * @covers \Tainacan_OAI_PMH\Item_Resolver
 */
class Item_Resolver_Test extends WP_UnitTestCase {

	private const COLLECTION_ID       = 99;
	private const OTHER_COLLECTION_ID = 42;

	private Item_Resolver $resolver;
	private int $post_id;

	public function setUp(): void {
		parent::setUp();

		// Register the post types that the resolver scopes by.
		register_post_type( 'tnc_col_' . self::COLLECTION_ID . '_item' );
		register_post_type( 'tnc_col_' . self::OTHER_COLLECTION_ID . '_item' );

		$this->resolver = new Item_Resolver();

		$this->post_id = $this->factory()->post->create(
			array(
				'post_type'   => 'tnc_col_' . self::COLLECTION_ID . '_item',
				'post_status' => 'publish',
				'post_title'  => 'OAI Item',
			)
		);
		update_post_meta( $this->post_id, Item_Resolver::META_KEY_SOURCE_ID, 'oai:example.org:abc-123' );
		update_post_meta( $this->post_id, Item_Resolver::META_KEY_SOURCE_DATESTAMP, '2024-01-15T00:00:00Z' );
	}

	public function test_find_by_oai_identifier_returns_post_id_when_present(): void {
		$found = $this->resolver->find_by_oai_identifier( 'oai:example.org:abc-123', self::COLLECTION_ID );
		$this->assertSame( $this->post_id, $found );
	}

	public function test_find_by_oai_identifier_returns_null_when_absent(): void {
		$this->assertNull(
			$this->resolver->find_by_oai_identifier( 'oai:example.org:does-not-exist', self::COLLECTION_ID )
		);
	}

	public function test_find_by_oai_identifier_returns_null_for_empty_identifier(): void {
		$this->assertNull( $this->resolver->find_by_oai_identifier( '' ) );
	}

	public function test_find_by_oai_identifier_scopes_by_collection(): void {
		// Same OAI identifier in another collection must NOT match when scoping.
		$other = $this->factory()->post->create(
			array(
				'post_type'   => 'tnc_col_' . self::OTHER_COLLECTION_ID . '_item',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $other, Item_Resolver::META_KEY_SOURCE_ID, 'oai:example.org:abc-123' );

		$found_in_target = $this->resolver->find_by_oai_identifier( 'oai:example.org:abc-123', self::COLLECTION_ID );
		$found_in_other  = $this->resolver->find_by_oai_identifier( 'oai:example.org:abc-123', self::OTHER_COLLECTION_ID );

		$this->assertSame( $this->post_id, $found_in_target );
		$this->assertSame( $other, $found_in_other );
		$this->assertNotSame( $found_in_target, $found_in_other );
	}

	public function test_find_by_oai_identifier_excludes_trashed(): void {
		wp_trash_post( $this->post_id );
		$this->assertNull(
			$this->resolver->find_by_oai_identifier( 'oai:example.org:abc-123', self::COLLECTION_ID )
		);
	}

	public function test_find_trashed_by_oai_identifier_finds_trashed_items(): void {
		wp_trash_post( $this->post_id );
		$found = $this->resolver->find_trashed_by_oai_identifier( 'oai:example.org:abc-123', self::COLLECTION_ID );
		$this->assertSame( $this->post_id, $found );
	}

	public function test_find_trashed_by_oai_identifier_returns_null_for_live_items(): void {
		// Item is still 'publish', not 'trash'.
		$this->assertNull(
			$this->resolver->find_trashed_by_oai_identifier( 'oai:example.org:abc-123', self::COLLECTION_ID )
		);
	}

	public function test_find_in_other_collections_returns_matches_outside_excluded(): void {
		$other = $this->factory()->post->create(
			array(
				'post_type'   => 'tnc_col_' . self::OTHER_COLLECTION_ID . '_item',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $other, Item_Resolver::META_KEY_SOURCE_ID, 'oai:example.org:abc-123' );

		$matches = $this->resolver->find_in_other_collections( 'oai:example.org:abc-123', self::COLLECTION_ID );

		$this->assertCount( 1, $matches );
		$this->assertSame( $other, $matches[0]['id'] );
		$this->assertSame( self::OTHER_COLLECTION_ID, $matches[0]['collection_id'] );
	}

	public function test_find_in_other_collections_skips_excluded(): void {
		$matches = $this->resolver->find_in_other_collections( 'oai:example.org:abc-123', self::COLLECTION_ID );
		// Only the seed item exists, and it's in the excluded collection.
		$this->assertSame( array(), $matches );
	}

	public function test_untrash_attachments_restores_attachments_only(): void {
		// Create two attachments parented to the item.
		$att1 = $this->factory()->attachment->create_object(
			'image1.jpg',
			$this->post_id,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			)
		);
		$att2 = $this->factory()->attachment->create_object(
			'image2.jpg',
			$this->post_id,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			)
		);
		wp_trash_post( $att1 );
		wp_trash_post( $att2 );

		$restored = $this->resolver->untrash_attachments( $this->post_id );

		$this->assertSame( 2, $restored );
		$this->assertNotSame( 'trash', get_post_status( $att1 ) );
		$this->assertNotSame( 'trash', get_post_status( $att2 ) );
	}

	public function test_item_has_oai_bitstreams_detects_tagged_attachment(): void {
		$this->assertFalse( $this->resolver->item_has_oai_bitstreams( $this->post_id ) );

		$att = $this->factory()->attachment->create_object(
			'image.jpg',
			$this->post_id,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			)
		);
		update_post_meta( $att, '_oai_bitstream_url', 'https://example.org/bitstream/123' );

		$this->assertTrue( $this->resolver->item_has_oai_bitstreams( $this->post_id ) );
	}
}
