<?php
/**
 * Unit tests for Metadata_Mapper helpers that don't need DB access.
 *
 * derive_field_label() is the new entry point — it produces the
 * human-readable label rendered in the importer wizard's mapping table.
 * Previously the wizard showed raw dotted keys (`colaborador.autor`),
 * which DAMI's admins called out as unreadable when mapping custom
 * Portuguese DSpace schemas.
 *
 * @package Tainacan_OAI_PMH
 */

use Tainacan_OAI_PMH\Metadata_Mapper;

/**
 * @covers \Tainacan_OAI_PMH\Metadata_Mapper::derive_field_label
 */
class Metadata_Mapper_Test extends WP_UnitTestCase {

	public function test_derive_field_label_returns_translated_label_for_standard_dc(): void {
		// `get_standard_dc_fields()` translates these via __(); in the test
		// environment the WP test bootstrap doesn't switch locales, so we
		// expect the English source string back. The point of the assertion
		// is that the standard-DC branch fires BEFORE the breadcrumb path —
		// "title" should NOT come out as "Title" via ucwords().
		$this->assertSame( 'Title', Metadata_Mapper::derive_field_label( 'title' ) );
		$this->assertSame( 'Creator', Metadata_Mapper::derive_field_label( 'creator' ) );
		$this->assertSame( 'Rights', Metadata_Mapper::derive_field_label( 'rights' ) );
	}

	public function test_derive_field_label_strips_dc_prefix_and_uses_standard_label(): void {
		// xoai emits `dc.title` for the same concept that oai_dc emits as
		// `title`. After stripping the `dc.` namespace, the remainder is
		// a known standard DC element and gets the translated label, not
		// a "Title" breadcrumb.
		$this->assertSame( 'Title', Metadata_Mapper::derive_field_label( 'dc.title' ) );
		$this->assertSame( 'Subject', Metadata_Mapper::derive_field_label( 'dc.subject' ) );
	}

	public function test_derive_field_label_breadcrumbs_qualified_dspace_fields(): void {
		// Qualified xoai paths beyond the basic 15 DCMES elements become
		// breadcrumbs so the admin reads "Contributor › Author", not
		// "dc.contributor.author".
		$this->assertSame(
			'Contributor › Author',
			Metadata_Mapper::derive_field_label( 'dc.contributor.author' )
		);
		$this->assertSame(
			'Description › Abstract',
			Metadata_Mapper::derive_field_label( 'dc.description.abstract' )
		);
	}

	public function test_derive_field_label_handles_custom_portuguese_schemas(): void {
		// Production case: DAMI Museu Imperial exposes a custom DSpace
		// schema where qualifiers are Portuguese (`colaborador.autor`,
		// `dimensoes.altura`). The breadcrumb path must titlecase each
		// segment WITHOUT mangling UTF-8 (mb_convert_case).
		$this->assertSame(
			'Colaborador › Autor',
			Metadata_Mapper::derive_field_label( 'colaborador.autor' )
		);
		$this->assertSame(
			'Dimensoes › Altura',
			Metadata_Mapper::derive_field_label( 'dimensoes.altura' )
		);
		$this->assertSame(
			'Data › Incorporacao',
			Metadata_Mapper::derive_field_label( 'data.incorporacao' )
		);
	}

	public function test_derive_field_label_replaces_underscores_with_spaces(): void {
		// xoai sometimes emits `dc.subject.classification_other` style keys
		// (snake_case inside a segment); admin wants spaces in the label.
		$this->assertSame(
			'Outras Dimensoes',
			Metadata_Mapper::derive_field_label( 'outras_dimensoes' )
		);
	}

	public function test_derive_field_label_strips_dcterms_prefix(): void {
		// qdc emits dcterms-namespaced keys; the namespace is uninteresting
		// to admins and should disappear from the label.
		$this->assertSame(
			'Is Part Of',
			Metadata_Mapper::derive_field_label( 'dcterms.is_part_of' )
		);
	}

	public function test_derive_field_label_empty_returns_empty(): void {
		// Defensive: empty input from a malformed source field must not
		// throw and must not produce a stray separator.
		$this->assertSame( '', Metadata_Mapper::derive_field_label( '' ) );
		$this->assertSame( '', Metadata_Mapper::derive_field_label( '   ' ) );
	}

	public function test_derive_field_label_single_segment_titlecases(): void {
		// Non-standard single-segment names (e.g. `identificacao`, `tecnica`)
		// don't get a breadcrumb separator — just titlecase.
		$this->assertSame( 'Identificacao', Metadata_Mapper::derive_field_label( 'identificacao' ) );
		$this->assertSame( 'Tecnica', Metadata_Mapper::derive_field_label( 'tecnica' ) );
	}
}
