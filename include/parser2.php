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

    private static function parse_text(&$errors, $context = null) {
        $tree = array();
        $close_tag = is_null($context) ? null : '[/' . $context . ']';
        while (self::$i < self::$limit) {
            $char = self::$chars[self::$i];
            if ($char == '[' && self::$i + 2 < self::$limit) {
                if (self::$chars[self::$i + 1] != '/') {
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

        while (self::$i < self::$limit && preg_match("/[a-zA-Z\*]/", self::$chars[self::$i])) {
            self::$string_buffer[] = self::$chars[self::$i++];
        }
        $tag = self::get_string_buffer();
        if (empty($tag))
            return new SyntaxTreeTextNode('[');

        if (self::$i < self::$limit && self::$chars[self::$i] == '=')
            $attr = self::parse_attr($errors);
        else
            $attr = null;

        // must find ] or else it's not actually a tag
        if (self::$i >= self::$limit || self::$chars[self::$i] != ']')
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
                $expected_tag) == 0;
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
            $tree[] = new SyntaxTreeTextNode($string);
        }
    }
}

// TODO: test script, delete
$errors = [];
$tree = BBCodeParser::generateSyntaxTree(file_get_contents($argv[1]), $errors);
echo 'tree:' . PHP_EOL;
var_dump($tree);
echo 'errors:' . PHP_EOL;
var_dump($errors);
echo PHP_EOL;
