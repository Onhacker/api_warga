<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Passive, self-contained letter snapshots, safe to view and download as HTML. */
class Official_letter_html
{
    const POLICY = "default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'";

    public static function valid($html)
    {
        if (!is_string($html) || strlen($html) > 8 * 1024 * 1024
            || !preg_match('/^\s*<!doctype html>/i', $html) || strpos($html, "\0") !== FALSE
            || preg_match('/<!ENTITY|<!\[CDATA\[|<\?/i', $html)
            || !class_exists('DOMDocument')) return FALSE;
        $previous = libxml_use_internal_errors(TRUE);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) return FALSE;
        $tags = explode(' ', 'html head meta title style body main section article header footer div span p br hr b strong i em u s small sub sup h1 h2 h3 h4 h5 h6 table thead tbody tfoot tr th td colgroup col ul ol li dl dt dd img a pre blockquote');
        $attributes = explode(' ', 'lang charset name content http-equiv class id style title alt src href width height align valign border cellpadding cellspacing colspan rowspan span scope start type reversed');
        $hasPolicy = FALSE;
        foreach ($dom->getElementsByTagName('*') as $node) {
            $tag = strtolower($node->tagName);
            if (!in_array($tag, $tags, TRUE)) return FALSE;
            foreach ($node->attributes as $attribute) {
                $name = strtolower($attribute->name);
                if (!in_array($name, $attributes, TRUE)) return FALSE;
                if ($name === 'style' && !self::safe_css($attribute->value)) return FALSE;
                if ($name === 'src' && ($tag !== 'img' || !self::image($attribute->value))) return FALSE;
                if ($name === 'href' && ($tag !== 'a' || !self::verification_link($attribute->value))) return FALSE;
                if ($name === 'http-equiv' && $tag !== 'meta') return FALSE;
            }
            if ($tag === 'style' && !self::safe_css($node->textContent)) return FALSE;
            if ($tag === 'meta') {
                if ($node->hasAttribute('http-equiv')) {
                    if (strtolower($node->getAttribute('http-equiv')) !== 'content-security-policy'
                        || $node->getAttribute('content') !== self::POLICY) return FALSE;
                    $hasPolicy = TRUE;
                } elseif ($node->hasAttribute('charset')) {
                    if (strtolower($node->getAttribute('charset')) !== 'utf-8') return FALSE;
                } elseif (strtolower($node->getAttribute('name')) !== 'viewport') return FALSE;
            }
        }
        return $hasPolicy;
    }

    private static function safe_css($css)
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        return strpos($css, chr(92)) === FALSE
            && !preg_match('/url\s*\(|expression\s*\(|behavior\s*:|-moz-binding|@(?!media\b|page\b)/i', $css)
            && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $css);
    }

    private static function image($uri)
    {
        if (!preg_match('#^data:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/=]+)$#D', $uri, $parts)) return FALSE;
        $bytes = base64_decode($parts[2], TRUE);
        $info = is_string($bytes) ? @getimagesizefromstring($bytes) : FALSE;
        return $info && isset($info['mime']) && $info['mime'] === 'image/' . $parts[1];
    }

    private static function verification_link($uri)
    {
        $uri = trim(html_entity_decode((string) $uri, ENT_QUOTES, 'UTF-8'));
        if ($uri === '' || preg_match('/[\r\n]/', $uri)) return FALSE;
        $parts = parse_url($uri);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), array('http', 'https'), TRUE)) return FALSE;
        foreach (array('user', 'pass', 'query', 'fragment') as $forbiddenPart) {
            if (array_key_exists($forbiddenPart, $parts)) return FALSE;
        }

        $allowed = rtrim(trim((string) getenv('WARGA_ALLOWED_ORIGIN')), '/');
        $allowedParts = parse_url($allowed);
        if (!is_array($allowedParts) || empty($allowedParts['scheme']) || empty($allowedParts['host'])) return FALSE;
        if (strcasecmp((string) $allowedParts['host'], (string) $parts['host']) !== 0
            || (int) ($allowedParts['port'] ?? 0) !== (int) ($parts['port'] ?? 0)
            || strtolower((string) $allowedParts['scheme']) !== strtolower((string) $parts['scheme'])) return FALSE;

        $basePath = rtrim((string) ($allowedParts['path'] ?? ''), '/');
        $path = (string) ($parts['path'] ?? '');
        $prefix = $basePath . '/verifikasi-surat/';
        if (strpos($path, $prefix) !== 0) return FALSE;
        $suffix = substr($path, strlen($prefix));
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $suffix) === 1
            || preg_match('/^lokal\/[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $suffix) === 1;
    }
}
