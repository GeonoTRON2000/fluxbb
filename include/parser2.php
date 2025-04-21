<?php
/**
 * Copyright (C) 2025 GeonoTRON2000
 * based on code by FluxBB copyright (C) 2008-2012 FluxBB
 * based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

 // Make sure no one attempts to run this script "directly"
//if (!defined('PUN'))
//    exit;

class SyntaxTreeNode {}
class SyntaxTreeTextNode extends SyntaxTreeNode {
    public string $text;

    public function __construct($text) {
        $this->text = $text;
    }
}
class SyntaxTreeTagNode extends SyntaxTreeNode {
    public string $tag;
    public ?string $attribute;
    public array $children;

    public function __construct($tag, $attribute, $children) {
        $this->tag = $tag;
        $this->attribute = $attribute;
        $this->children = $children;
    }
}

class BBCodeParser {
    private static $all_tags = array('quote', 'img', 'code', 'url', 'topic', 'post', 'forum', 'user',
                                     'email', 'b', 'i', 'u', 's', 'c', 'h', 'del', 'ins', 'em', 'color',
                                     'colour', 'list', '*');
    private static $attr_permitted = array('quote', 'img', 'url', 'topic', 'post', 'forum', 'user',
                                            'email', 'color', 'colour', 'list');
    private static $attr_required = array('color', 'colour');

    private static int $i;
    private static int $limit;
    private static int $mode;
    private static array $chars;
    private static array $string_buffer;

    public static function generateSyntaxTree($text, &$errors) {
        self::$i = 0;
        self::$chars = str_split($text);
        self::$limit = count(self::$chars);
        self::$string_buffer = array();

        $tree = self::parse_text($errors);
        return empty($errors) ? $tree : array();
    }

    public static function generateCode(&$tree, $is_signature = false) {
        $message_parts = array();
        foreach ($tree as $node) {
            if ($node instanceof SyntaxTreeTextNode) {
                $message_parts[] = self::generate_text($node);
            } else if ($node instanceof SyntaxTreeTagNode) {
                switch ($tag = strtolower($node->tag)) {
                    case 'img':
                        $message_parts[] = self::generate_img_tag($node, $is_signature);
                        break;
                    case 'code':
                        $message_parts[] = self::generate_code_tag($node);
                        break;
                    case 'url':
                    case 'topic':
                    case 'post':
                    case 'forum':
                    case 'user':
                    case 'email':
                        $message_parts[] = self::generate_url_tag($tag, $node);
                    case 'list':
                        $message_parts[] = self::generate_list_tag($tag, $node);
                    default:
                        $message_parts[] = self::generate_open_tag($tag, $node->attribute);
                        $message_parts[] = self::generateCode($node->children);
                        $message_parts[] = self::generate_close_tag($tag);
                }
            }
        }
        return implode('', $message_parts);
    }

    private static function genereate_img_tag(&$node, $is_signature = false) {
        global $lang_common, $pun_user;

        $url = self::node_interior_as_text($node);
        $alt = is_null($node->attribute) ? basename($url) : $node->attribute;
        $url = pun_htmlspecialchars($url);
        $alt = pun_htmlspecialchars($alt);

        if ($is_signature && $pun_user['show_img_sig'] !== '0')
    		$img_tag = '<img class="sigimage" src="' . $url . '" alt="' . $alt . '" />';
	    else if (!$is_signature && $pun_user['show_img'] !== '0')
		    $img_tag = '<span class="postimg"><img src="' . $url . '" alt="' . $alt . '" /></span>';
        else
            $img_tag = '<a href="' . $url . '" rel="nofollow">&lt;'.$lang_common['Image link'].' - '. $alt .'&gt;</a>';

    	return $img_tag;
    }

    private static function generate_code_tag(&$node) {
        $code = pun_htmlspecialchars(pun_trim(self::node_interior_as_text($node), "\n\r"));
        $num_lines = substr_count($code, "\n");
        return '</p><div class="codebox"><pre'
            . (($num_lines > 28) ? ' class="vscroll"' : '')
            . '><code>' . $code .'</code></pre></div><p>';
    }

    private static function generate_url_tag(&$tag, &$node) {
        // permit [url][img]blah[/img][/url]
        $url = pun_trim(
                is_null($node->attribute)
                    ? self::node_interior_as_text($node, true)
                    : $node->attribute);
        switch ($tag) {
            case 'topic':
                $url = get_base_url(true) . '/viewtopic.php?id=' . intval($url);
                break;
            case 'post':
                $url = get_base_url(true) . '/viewtopic.php?pid=' . intval($url) . '#p' . intval($url);
                break;
            case 'forum':
                $url = get_base_url(true) . '/viewforum.php?id=' . intval($url);
                break;
            case 'user':
                $url = get_base_url(true) . '/profile.php?id=' . intval($url);
                break;
            case 'email':
                $url = 'mailto:' . $url;
                break;
        }

        // TODO: limit this to a single text node or image, potentially just via validations
        $interior = self::generateCode($node->children);
        $safe_url = pun_htmlspecialchars($url);
        if ($interior === $safe_url)
            $interior = utf8_strlen($url) > 55
                ? pun_htmlspecialchars(utf8_substr($url, 0 , 39) . ' … ' . utf8_substr($url, -10)) 
                : $safe_url;

        $url = self::prepend_protocol($url);
        return '<a href="' . pun_htmlspecialchars($url). '" rel="nofollow">'
                    . $interior . '</a>';
    }

    private static function generate_list_tag(&$node) {
        $type = is_null($node->attribute) ? '*' : $node->attribute;
        $items = array();

        foreach ($node->children as $item) {
            if ($item instanceof SyntaxTreeTextNode)
                $items[] = '<li><p>' . self::generate_text($item) . '</li></p>';
            else if (($item instanceof SyntaxTreeTagNode) && $item->tag === '*')
                $items[] = '<li><p>' . self::generateCode($item->children) . '</li></p>';
            // TODO: validations should prevent any other case
        }

        $interior = implode('', $items);
        if ($type === '1')
            return '</p><ol class="decimal">' . $interior . '</ol><p>';
        else if ($type === 'a')
            return '</p><ol class="alpha">' . $interior . '</ol><p>';
        else
            return '</p><ul>' . $interior . '</ul><p>';
    }

    private static function generate_open_tag(&$tag, &$attr) {
        global $lang_common;

        $safe_attr = is_null($attr) ? null : pun_htmlspecialchars($attr);
        switch ($tag) {
            case 'quote':
                if (is_null($safe_attr))
                    return '</p><div class="quotebox"><blockquote><div><p>';
                else
                    return '</p><div class="quotebox"><cite>'
                        . self::unquote($safe_attr) . ' ' . $lang_common['wrote']
                        . '</cite><blockquote><div><p>';
            case 'b':
                return '<strong>';
            case 'i':
                return '<em>';
            case 'u':
                return '<span class="bbu">';
            case 's':
                return '<span class="bbs>';
            case 'c':
                return '<code class="code">';
            case 'del':
                return '<del>';
            case 'ins':
                return '<ins>';
            case 'em':
                return '<em>';
            case 'color':
            case 'colour':
                return '<span style="color: ' . $safe_attr . ';">';
            case 'h':
                return '</p><h5>';
        }
    }

    private static function generate_close_tag(&$tag) {
        switch ($tag) {
            case 'quote':
                return '</p></div></blockquote></div><p>';
            case 'b':
                return '</strong>';
            case 'i':
                return '</em>';
            case 'u':
                return '</span>';
            case 's':
                return '</span>';
            case 'c':
                return '</code>';
            case 'del':
                return '</del>';
            case 'ins':
                return '</ins>';
            case 'em':
                return '</em>';
            case 'color':
            case 'colour':
                return '</span>';
            case 'h':
                return '</h5><p>';
        }
    }

    private static function node_interior_as_text(&$node, $allow_images = false) {
        if (empty($node->children))
            return '';

        $interior = $node->children[0];
        if ($interior instanceof SyntaxTreeTextNode)
            return $interior->text;
        if ($allow_images && ($interior instanceof SyntaxTreeTagNode)
                && strcasecmp($interior->tag, 'img'))
            // TODO: validate this better if we use it in validation
            return $interior->children[0]->text;
        // TODO: validate against ever reaching this
    }

    private static function generate_text(&$node) {
        // TODO: all the smilies and line parsing and shit go here
        return pun_htmlspecialchars($node->text);
    }

    private static function parse_text(&$errors, $context = null) {
        $tree = array();
        $close_tag = is_null($context) ? null : '[/' . $context . ']';
        // text within [code] should not be touched
        $preformatted_context = !is_null($context) && strcasecmp($context, 'code') === 0;

        while (self::$i < self::$limit) {
            $char = self::$chars[self::$i];
            if ($char === '[' && self::$i + 2 < self::$limit) {
                if (!$preformatted_context && self::$chars[self::$i + 1] !== '/') {
                    self::dump_string_buffer($tree);
                    $tree[] = self::parse_tag($errors);
                    continue;
                } else if (!is_null($context) && self::matches_close_tag($close_tag)) {
                    self::dump_string_buffer($tree);
                    return $tree;
                }
            }
            self::$string_buffer[] = $char;
            self::$i++;
        }
        self::dump_string_buffer($tree);

        if (!is_null($context))
            $errors[] = 'missing close tag: ' . $close_tag;

        return $tree;
    }

    private static function parse_tag(&$errors) {
        // consume [
        self::$i++;

        while (self::$i < self::$limit && preg_match("%[a-zA-Z\*]%s", self::$chars[self::$i])) {
            self::$string_buffer[] = self::$chars[self::$i++];
        }
        $tag = self::get_string_buffer();
        if (empty($tag))
            return new SyntaxTreeTextNode('[');

        if (self::$i < self::$limit && self::$chars[self::$i] === '=')
            $attr = self::parse_attr($errors);
        else
            $attr = null;

        // must find ] or else it's not actually a tag
        if (self::$i >= self::$limit || self::$chars[self::$i] !== ']'
                || !self::is_valid_tag($tag, $attr))
            return new SyntaxTreeTextNode('[' . $tag . (is_null($attr) ? '' : '=' . $attr));

        // consume ]
        self::$i++;

        $children = self::parse_text($errors, $tag);

        // consume close tag (control will not return from `parse_text` unless it's correct)
        self::$i += strlen($tag) + 3;
        return new SyntaxTreeTagNode($tag, $attr, $children);
    }

    private static function parse_attr(&$errors) {
        // consume =
        self::$i++;

        $terminals = array(']', "\n");
        while (self::$i < self::$limit && !in_array(self::$chars[self::$i], $terminals)) {
            self::$string_buffer[] = self::$chars[self::$i++];
        }
        return self::get_string_buffer();
    }

    private static function matches_close_tag($expected_tag) {
        return self::$i + strlen($expected_tag) <= self::$limit
            && strcasecmp(
                implode(
                    '',
                    array_slice(self::$chars, self::$i, strlen($expected_tag))),
                $expected_tag) === 0;
    }

    private static function is_valid_tag(&$tag, &$attr) {
        $lc_tag = strtolower($tag);
        return in_array($lc_tag, self::$all_tags)
            && (is_null($attr) || self::$attr_permitted[$lc_tag])
            && (!is_null($attr) || !self::$attr_required[$lc_tag])
            && (is_null($attr) || $lc_tag !== 'list' || in_array($attr, array('1', 'a', '*')));
    }

    private static function prepend_protocol($url) {
        $full_url = str_replace(array(' ', '\'', '`', '"'), array('%20', '', '', ''), $url);
        if (strpos($url, 'www.') === 0) // If it starts with www, we add http://
            $full_url = 'http://'.$full_url;
        else if (strpos($url, 'ftp.') === 0) // Else if it starts with ftp, we add ftp://
            $full_url = 'ftp://'.$full_url;
        else if (strpos($url, '//') === 0) // Allow for protocol relative URLs that start with a double slash
            $full_url = get_current_protocol().':'.$full_url;
        else if (strpos($url, '/') === 0) // Allow for host relative URLs that start with a slash
            $full_url = get_base_url(true).$full_url;
        else if (!preg_match('#^([a-z0-9]{3,6})://#', $url)) // Else if it doesn't start with abcdef://, we add http://
            $full_url = 'http://'.$full_url;

        return $full_url;
    }

    private static function unquote($quoted) {
        return preg_replace('%(&quot;|&\#039;|"|\'|)(.*)\\1%s', '$2', $quoted);
    }

    private static function get_string_buffer() {
        if (empty(self::$string_buffer)) return '';
        $string = implode('', self::$string_buffer);
        self::$string_buffer = array();
        return $string;        
    }

    private static function dump_string_buffer(&$tree) {
        if (!empty(self::$string_buffer)) {
            $string = implode('', self::$string_buffer);
            self::$string_buffer = array();
            
            if (!empty($tree) && (($last = $tree[count($tree) - 1]) instanceof SyntaxTreeTextNode))
                $last->text .= $string;
            else
                $tree[] = new SyntaxTreeTextNode($string);
        }
    }
}

// TODO: test script, delete
require('./functions.php');
$errors = [];
$tree = BBCodeParser::generateSyntaxTree(file_get_contents($argv[1]), $errors);
echo 'tree:' . PHP_EOL;
var_dump($tree);
echo 'errors:' . PHP_EOL;
var_dump($errors);
echo PHP_EOL;
