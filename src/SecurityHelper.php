<?php
namespace KutSocial;

/**
 * Security helpers for SSRF protection and remote HTML sanitization.
 */
class SecurityHelper {
    /**
     * Validate that a URL uses http/https and resolves only to public (non-private, non-reserved) IPs.
     */
    public static function isSafeExternalUrl(string $url): bool {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        // If host is an IP literal, validate directly
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        $ips = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $rec) {
                    if (!empty($rec['ip'])) {
                        $ips[] = $rec['ip'];
                    }
                    if (!empty($rec['ipv6'])) {
                        $ips[] = $rec['ipv6'];
                    }
                }
            }
        }

        if (empty($ips) && function_exists('gethostbynamel')) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = array_merge($ips, $v4);
            }
        }

        if (empty($ips)) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sanitize remote ActivityPub HTML using a strict DOMDocument tag and attribute allowlist.
     */
    public static function sanitizeRemoteHtml(string $html): string {
        if (trim($html) === '') {
            return '';
        }

        if (!class_exists('DOMDocument')) {
            $clean = strip_tags($html, '<p><br><a><span><strong><b><em><i><ul><ol><li><blockquote><code><pre>');
            $clean = preg_replace('/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
            $clean = preg_replace('/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '$1=$2#$2', $clean);
            return trim((string)$clean);
        }

        $allowedTags = ['p', 'br', 'a', 'span', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'blockquote', 'code', 'pre'];
        $dropEntirelyTags = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'textarea', 'button', 'noscript'];

        $prevErrors = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="__ks_root__">' . $html . '</div></body></html>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);

        $root = $doc->getElementById('__ks_root__');
        if (!$root) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        }

        self::sanitizeDomNode($root, $allowedTags, $dropEntirelyTags);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $doc->saveHTML($child);
        }
        return trim($output);
    }

    private static function sanitizeDomNode(\DOMNode $node, array $allowedTags, array $dropEntirelyTags): void {
        if (!$node->hasChildNodes()) {
            return;
        }

        // Iterate over a static snapshot because we may remove/replace children
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof \DOMComment) {
                $node->removeChild($child);
                continue;
            }
            if (!($child instanceof \DOMElement)) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, $dropEntirelyTags, true)) {
                $node->removeChild($child);
                continue;
            }

            // Recursively sanitize children first
            self::sanitizeDomNode($child, $allowedTags, $dropEntirelyTags);

            if (!in_array($tag, $allowedTags, true)) {
                // Unwrap disallowed tag, keeping its sanitized children
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            // Filter attributes strictly
            $attrsToRemove = [];
            if ($child->hasAttributes()) {
                foreach ($child->attributes as $attr) {
                    $attrName = strtolower($attr->name);
                    if ($tag === 'a') {
                        if ($attrName === 'href') {
                            $href = trim($attr->value);
                            if (!preg_match('/^https?:\/\//i', $href)) {
                                $attrsToRemove[] = $attr->name;
                            }
                        } elseif ($attrName === 'class') {
                            if (!preg_match('/^[a-zA-Z0-9 _-]+$/', $attr->value)) {
                                $attrsToRemove[] = $attr->name;
                            }
                        } elseif ($attrName === 'rel') {
                            if (!preg_match('/^[a-zA-Z0-9 _-]+$/', $attr->value)) {
                                $attrsToRemove[] = $attr->name;
                            }
                        } elseif ($attrName === 'target') {
                            if ($attr->value !== '_blank') {
                                $attrsToRemove[] = $attr->name;
                            }
                        } else {
                            $attrsToRemove[] = $attr->name;
                        }
                    } elseif ($tag === 'span' && $attrName === 'class') {
                        if (!preg_match('/^[a-zA-Z0-9 _-]+$/', $attr->value)) {
                            $attrsToRemove[] = $attr->name;
                        }
                    } else {
                        $attrsToRemove[] = $attr->name;
                    }
                }
            }
            foreach ($attrsToRemove as $attrName) {
                $child->removeAttribute($attrName);
            }
            if ($tag === 'a' && $child->hasAttribute('href')) {
                $child->setAttribute('rel', 'nofollow noopener noreferrer');
            }
        }
    }
}
