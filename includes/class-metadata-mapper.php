<?php
namespace Tainacan_OAI_PMH;

if (!defined('ABSPATH')) exit;

class Metadata_Mapper {
    
    public static function get_dc_fields() {
        return [
            'title' => ['label' => __('Title', 'tainacan-oai-pmh'), 'description' => __('Resource name', 'tainacan-oai-pmh')],
            'creator' => ['label' => __('Creator', 'tainacan-oai-pmh'), 'description' => __('Entity responsible for making', 'tainacan-oai-pmh')],
            'subject' => ['label' => __('Subject', 'tainacan-oai-pmh'), 'description' => __('Topic of the resource', 'tainacan-oai-pmh')],
            'description' => ['label' => __('Description', 'tainacan-oai-pmh'), 'description' => __('Account of the resource', 'tainacan-oai-pmh')],
            'publisher' => ['label' => __('Publisher', 'tainacan-oai-pmh'), 'description' => __('Entity making available', 'tainacan-oai-pmh')],
            'contributor' => ['label' => __('Contributor', 'tainacan-oai-pmh'), 'description' => __('Contributing entity', 'tainacan-oai-pmh')],
            'date' => ['label' => __('Date', 'tainacan-oai-pmh'), 'description' => __('Point or period of time', 'tainacan-oai-pmh')],
            'type' => ['label' => __('Type', 'tainacan-oai-pmh'), 'description' => __('Nature or genre', 'tainacan-oai-pmh')],
            'format' => ['label' => __('Format', 'tainacan-oai-pmh'), 'description' => __('File format or dimensions', 'tainacan-oai-pmh')],
            'identifier' => ['label' => __('Identifier', 'tainacan-oai-pmh'), 'description' => __('Unambiguous reference', 'tainacan-oai-pmh')],
            'source' => ['label' => __('Source', 'tainacan-oai-pmh'), 'description' => __('Related resource derived from', 'tainacan-oai-pmh')],
            'language' => ['label' => __('Language', 'tainacan-oai-pmh'), 'description' => __('Language of the resource', 'tainacan-oai-pmh')],
            'relation' => ['label' => __('Relation', 'tainacan-oai-pmh'), 'description' => __('Related resource', 'tainacan-oai-pmh')],
            'coverage' => ['label' => __('Coverage', 'tainacan-oai-pmh'), 'description' => __('Spatial or temporal topic', 'tainacan-oai-pmh')],
            'rights' => ['label' => __('Rights', 'tainacan-oai-pmh'), 'description' => __('Rights information', 'tainacan-oai-pmh')],
        ];
    }
    
    public static function get_collection_metadata($collection_id) {
        $collection = new \Tainacan\Entities\Collection($collection_id);
        if (!$collection->get_id()) return [];
        
        $repo = \Tainacan\Repositories\Metadata::get_instance();
        $metadata = $repo->fetch_by_collection($collection, [], 'OBJECT');
        
        $result = [];
        if (is_array($metadata)) {
            foreach ($metadata as $metadatum) {
                $mapping = $metadatum->get_exposer_mapping();
                $dc = null;
                if (isset($mapping['dublin-core'])) {
                    $dc = str_replace('dc:', '', $mapping['dublin-core']);
                }
                $result[] = [
                    'id' => $metadatum->get_id(),
                    'name' => $metadatum->get_name(),
                    'type' => $metadatum->get_metadata_type(),
                    'multiple' => $metadatum->is_multiple(),
                    'dc_mapping' => $dc,
                ];
            }
        }
        return $result;
    }
    
    public static function suggest_mapping($collection_id, $source_fields) {
        $metadata = self::get_collection_metadata($collection_id);
        $suggestions = [];
        
        foreach ($source_fields as $field) {
            $field_name = strtolower($field['name']);
            $best_match = null;
            $best_score = 0;
            
            foreach ($metadata as $meta) {
                $meta_name = strtolower($meta['name']);
                
                if ($meta['dc_mapping'] === $field_name) {
                    $best_match = $meta['id'];
                    break;
                }
                
                $similarity = 0;
                similar_text($field_name, $meta_name, $similarity);
                if ($field_name === $meta_name) $similarity = 100;
                if (strpos($meta_name, $field_name) !== false || strpos($field_name, $meta_name) !== false) {
                    $similarity = max($similarity, 80);
                }
                
                if ($similarity > $best_score && $similarity >= 60) {
                    $best_score = $similarity;
                    $best_match = $meta['id'];
                }
            }
            $suggestions[$field_name] = $best_match;
        }
        return $suggestions;
    }
}
