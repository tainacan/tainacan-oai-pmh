<?php
namespace Tainacan_OAI_PMH;

if (!defined('ABSPATH')) exit;

class XML_Generator {
    
    private $dom;
    private $root;
    
    const OAI_NS = 'http://www.openarchives.org/OAI/2.0/';
    const XSI_NS = 'http://www.w3.org/2001/XMLSchema-instance';
    const DC_NS = 'http://purl.org/dc/elements/1.1/';
    const OAI_DC_NS = 'http://www.openarchives.org/OAI/2.0/oai_dc/';
    
    public function __construct() {
        $this->dom = new \DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;
    }
    
    public function init($base_url, $verb, $params = []) {
        $this->root = $this->dom->createElementNS(self::OAI_NS, 'OAI-PMH');
        $this->root->setAttributeNS(self::XSI_NS, 'xsi:schemaLocation', 
            self::OAI_NS . ' http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd');
        $this->dom->appendChild($this->root);
        
        $this->root->appendChild($this->dom->createElement('responseDate', gmdate('Y-m-d\TH:i:s\Z')));
        
        $request = $this->dom->createElement('request', htmlspecialchars($base_url));
        if ($verb) $request->setAttribute('verb', $verb);
        foreach ($params as $key => $value) {
            if ($key !== 'verb' && !empty($value)) $request->setAttribute($key, $value);
        }
        $this->root->appendChild($request);
        
        return $this;
    }
    
    public function add_error($code, $message = '') {
        $error = $this->dom->createElement('error', $this->safe($message));
        $error->setAttribute('code', $code);
        $this->root->appendChild($error);
        return $this;
    }
    
    public function create_identify($data) {
        $identify = $this->dom->createElement('Identify');
        foreach (['repositoryName', 'baseURL', 'protocolVersion', 'adminEmail', 
                  'earliestDatestamp', 'deletedRecord', 'granularity'] as $field) {
            if (isset($data[$field])) {
                $identify->appendChild($this->dom->createElement($field, $this->safe($data[$field])));
            }
        }
        $this->root->appendChild($identify);
        return $this;
    }
    
    public function create_metadata_formats() {
        $list = $this->dom->createElement('ListMetadataFormats');
        $format = $this->dom->createElement('metadataFormat');
        $format->appendChild($this->dom->createElement('metadataPrefix', 'oai_dc'));
        $format->appendChild($this->dom->createElement('schema', 'http://www.openarchives.org/OAI/2.0/oai_dc.xsd'));
        $format->appendChild($this->dom->createElement('metadataNamespace', self::OAI_DC_NS));
        $list->appendChild($format);
        $this->root->appendChild($list);
        return $this;
    }
    
    public function create_sets($sets) {
        if (empty($sets)) {
            return $this->add_error('noSetHierarchy', 'This repository does not support sets.');
        }
        $list = $this->dom->createElement('ListSets');
        foreach ($sets as $set) {
            $node = $this->dom->createElement('set');
            $node->appendChild($this->dom->createElement('setSpec', $this->safe($set['setSpec'])));
            $node->appendChild($this->dom->createElement('setName', $this->safe($set['setName'])));
            $list->appendChild($node);
        }
        $this->root->appendChild($list);
        return $this;
    }
    
    public function start_list($type) {
        $list = $this->dom->createElement($type);
        $this->root->appendChild($list);
        return $list;
    }
    
    public function add_record($list, $data, $include_metadata = true) {
        $record = $this->dom->createElement('record');
        $record->appendChild($this->create_header($data));
        if ($include_metadata && $data['status'] !== 'trash') {
            $record->appendChild($this->create_metadata($data['metadata'] ?? []));
        }
        $list->appendChild($record);
        return $this;
    }
    
    public function add_header($list, $data) {
        $list->appendChild($this->create_header($data));
        return $this;
    }
    
    private function create_header($data) {
        $header = $this->dom->createElement('header');
        if ($data['status'] === 'trash') $header->setAttribute('status', 'deleted');
        $header->appendChild($this->dom->createElement('identifier', $this->safe($data['identifier'])));
        $header->appendChild($this->dom->createElement('datestamp', $this->safe($data['datestamp'])));
        if (!empty($data['setSpec'])) {
            $header->appendChild($this->dom->createElement('setSpec', $this->safe($data['setSpec'])));
        }
        return $header;
    }
    
    private function create_metadata($dc_data) {
        $metadata = $this->dom->createElement('metadata');
        $dc = $this->dom->createElementNS(self::OAI_DC_NS, 'oai_dc:dc');
        $dc->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dc', self::DC_NS);
        $dc->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::XSI_NS);
        $dc->setAttributeNS(self::XSI_NS, 'xsi:schemaLocation', 
            self::OAI_DC_NS . ' http://www.openarchives.org/OAI/2.0/oai_dc.xsd');
        
        $dc_fields = ['title', 'creator', 'subject', 'description', 'publisher', 
                      'contributor', 'date', 'type', 'format', 'identifier', 
                      'source', 'language', 'relation', 'coverage', 'rights'];
        
        foreach ($dc_fields as $field) {
            if (!empty($dc_data[$field])) {
                $values = is_array($dc_data[$field]) ? $dc_data[$field] : [$dc_data[$field]];
                foreach ($values as $value) {
                    if (!empty($value)) {
                        $dc->appendChild($this->dom->createElement('dc:' . $field, $this->safe($value)));
                    }
                }
            }
        }
        $metadata->appendChild($dc);
        return $metadata;
    }
    
    public function add_resumption_token($list, $token = '', $total = null, $cursor = null, $expiration = null) {
        $rt = $this->dom->createElement('resumptionToken', $this->safe($token));
        if ($total !== null) $rt->setAttribute('completeListSize', (string) $total);
        if ($cursor !== null) $rt->setAttribute('cursor', (string) $cursor);
        if ($expiration !== null) $rt->setAttribute('expirationDate', $expiration);
        $list->appendChild($rt);
        return $this;
    }
    
    public function output() {
        return $this->dom->saveXML();
    }
    
    private function safe($value) {
        if ($value === null) return '';
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
