<?php
/**
 * Copyright (C) 2025 GeonoTRON2000
 * based on code by FluxBB copyright (C) 2008-2012 FluxBB
 * based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

 // Make sure no one attempts to run this script "directly"
// TODO: re-enable
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

    // Here you can add additional smilies if you like
    private static $smilies = array(
        ':)' => 'smile.png',
        '=)' => 'smile.png',
        ':|' => 'neutral.png',
        '=|' => 'neutral.png',
        ':(' => 'sad.png',
        '=(' => 'sad.png',
        ':D' => 'big_smile.png',
        '=D' => 'big_smile.png',
        ':o' => 'yikes.png',
        ':O' => 'yikes.png',
        ';)' => 'wink.png',
        ':/' => 'hmm.png',
        ':P' => 'tongue.png',
        ':p' => 'tongue.png',
        ':lol:' => 'lol.png',
        ':mad:' => 'mad.png',
        ':rolleyes:' => 'roll.png',
        ':cool:' => 'cool.png');

    private static int $i;
    private static int $limit;
    private static int $mode;
    private static string $chars;
    private static array $string_buffer;
    private static bool $is_signature;
    private static bool $hide_smilies;

    public static function generateHTML(
        &$tree, $is_signature = false, $hide_smilies = false) {
        self::$is_signature = $is_signature;
        self::$hide_smilies = $hide_smilies;

        $text = '<p>' . pun_trim(self::generate_code($tree)) . '</p>';
        // Replace any breaks next to paragraphs so our replace below catches them
        $text = preg_replace('%(</?p>)(?:\s*?<br />){1,2}%i', '$1', $text);
        $text = preg_replace('%(?:<br />\s*?){1,2}(</?p>)%i', '$1', $text);

        // Remove any empty paragraph tags (inserted via quotes/lists/code/etc) which should be stripped
        $text = str_replace('<p></p>', '', $text);

        $text = preg_replace('%<br />\s*?<br />%i', '</p><p>', $text);

        $text = str_replace('<p><br />', '<br /><p>', $text);
        $text = str_replace('<br /></p>', '</p><br />', $text);
        $text = str_replace('<p></p>', '<br /><br />', $text);

        return $text;
    }

    public static function buildSyntaxTree($text, &$errors) {
        global $pun_config;

        self::$chars = pun_trim($text);

        if ($pun_config['p_message_bbcode'] !== '1'
                || strpos(self::$chars, '[') === false || strpos(self::$chars, ']') === false)
            return array(new SyntaxTreeTextNode(self::$chars));

        self::$i = 0;
        self::$limit = strlen(self::$chars);
        self::$string_buffer = array();

        $tree = array();
        self::parse_text($tree, $errors);
        return empty($errors) ? $tree : array();
    }

    private static function generate_code(&$tree) {
        $message_parts = array();
        foreach ($tree as $node) {
            $message_parts[] = self::generate_node_code($node);
        }
        return implode('', $message_parts);
    }

    private static function generate_node_code(&$node) {
        if ($node instanceof SyntaxTreeTextNode) {
            return self::generate_text($node);
        } else if ($node instanceof SyntaxTreeTagNode) {
            switch ($tag = strtolower($node->tag)) {
                case 'img':
                    return self::generate_img_tag($node);
                case 'code':
                    return self::generate_code_tag($node);
                case 'url':
                case 'topic':
                case 'post':
                case 'forum':
                case 'user':
                case 'email':
                    return self::generate_url_tag($tag, $node);
                case 'list':
                    return self::generate_list_tag($node);
                default:
                    return self::generate_open_tag($tag, $node->attribute)
                            . self::generate_code($node->children) 
                            . self::generate_close_tag($tag);
            }
        }
    }

    private static function generate_img_tag(&$node) {
        global $lang_common, $pun_user;

        $url = pun_trim(self::node_interior_as_text($node));
        $alt = pun_htmlspecialchars(
            is_null($node->attribute) ? basename($url) : pun_trim($node->attribute));
        $url = pun_htmlspecialchars($url);

        if (self::$is_signature && $pun_user['show_img_sig'] !== '0')
    		$img_tag = '<img class="sigimage" src="' . $url . '" alt="' . $alt . '" />';
	    else if (!self::$is_signature && $pun_user['show_img'] !== '0')
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
        $custom_url = !is_null($node->attribute);
        // can either be [url]text[/url] or [url][img]link[/img][/url]
        // note: but not a [user][img]7[/img][/user]
        list($inner_text, $inner) = self::extract_url_interior($node, $custom_url);

        $url = $custom_url ? pun_trim($node->attribute) : $inner_text;
        switch ($tag) {
            case 'topic':
                $url = get_base_url(true) . '/viewtopic.php?id=' . intval($url);
                break;
            case 'post':
                $i_url = intval($url);
                $url = get_base_url(true) . '/viewtopic.php?pid=' . $i_url . '#p' . $i_url;
                break;
            case 'forum':
                $url = get_base_url(true) . '/viewforum.php?id=' . intval($url);
                break;
            case 'user':
                $url = get_base_url(true) . '/profile.php?id=' . intval($url);
                break;
        }

        $inner = is_null($inner) ? self::safe_truncate_url($url) : $inner;
        $url = self::prepend_protocol($url, $tag);
        return '<a href="' . pun_htmlspecialchars($url). '" rel="nofollow">'
                    . $inner . '</a>';
    }

    private static function safe_truncate_url($url) {
        return pun_htmlspecialchars(
            utf8_strlen($url) > 55
                ? utf8_substr($url, 0 , 39) . ' … ' . utf8_substr($url, -10)
                : $url);
    }

    private static function extract_url_interior(&$node, $custom_url) {
        $child = $node->children[0];
        if ($child instanceof SyntaxTreeTextNode) {
            return array(
                pun_trim($child->text),
                $custom_url ? self::generate_text($child) : null);
        } else if (($child instanceof SyntaxTreeTagNode)
                    && strcasecmp($child->tag, 'img') === 0) {
            return array(
                pun_trim(self::node_interior_as_text($child)),
                self::generate_img_tag($child));
        }
    }

    private static function generate_list_tag(&$node) {
        $type = is_null($node->attribute) ? '*' : $node->attribute;
        $items = array();

        foreach ($node->children as $item) {
            if (($item instanceof SyntaxTreeTagNode) && $item->tag === '*')
                $item_interior = self::generate_code($item->children);
            else
                $item_interior = self::generate_node_code($item);

            $items[] = '<li><p>' . $item_interior . '</p></li>';
        }

        $inner = implode('', $items);
        if ($type === '1')
            return '</p><ol class="decimal">' . $inner . '</ol><p>';
        else if ($type === 'a')
            return '</p><ol class="alpha">' . $inner . '</ol><p>';
        else
            return '</p><ul>' . $inner . '</ul><p>';
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
                return '<span class="bbs">';
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

    private static function generate_text(&$node) {
        global $pun_config, $pun_user;

        $text = $node->text;

        if ($pun_config['o_censoring'] === '1')
    		$text = censor_words($text);

        $text = pun_htmlspecialchars($node->text);

        // TODO: this should be moved or smth
        if ($pun_config['o_make_links'] === '1')
            $text = self::replace_links($text);

        if ($pun_config['o_smilies'] === '1'&& $pun_user['show_smilies'] === '1'
                && !self::$hide_smilies)
    		$text = self::replace_smilies($text);

    	// Deal with newlines, tabs and multiple spaces
	    $text = str_replace(
                    array("\n", "\t", '  ', '  '),
                    array('<br />', '&#160; &#160; ', '&#160; ', ' &#160;'),
                    $text);

        return $text;
    }

    private static function node_interior_as_text(&$node) {
        if (empty($node->children))
            return '';

        return $node->children[0]->text;
    }

    private static function prepend_protocol($url, $tag = 'url') {
        $full_url = str_replace(array(' ', '\'', '`', '"'), array('%20', '', '', ''), $url);
        if ($tag === 'email')
            $full_url = 'mailto:'.$full_url;
        else if (strpos($url, 'www.') === 0) // If it starts with www, we add http://
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

    private static function replace_links($text) {
        // TODO: broken; fix.
        $text = preg_replace_callback(
            '%(https?://|www\.)([a-z0-9\-]+\.)+([a-z0-9]{2,})(\/[a-z0-9\-\.\/]*(\?[a-z0-9\-_\.]+(=[a-z0-9\-_\.]*)?(&amp;[a-z0-9\-_\.]+(=[a-z0-9\-_\.]*)?)*)?(\#[a-z0-9\-_]*)?)?%i',
            function($matches) {
                $url = strcasecmp(substr($matches[0], 0, 4), 'http') !== 0
                    ? 'https://' . $matches[0] : $matches[0];
                return '<a href="' . $url . '" rel="nofollow">' . $url . '</a>';
            },
            $text);
    }

    private static function replace_smilies($text) {
        $text = ' ' . $text . ' ';

        foreach (self::$smilies as $smiley_text => $smiley_img)
        {
            if (strpos($text, $smiley_text) !== false)
                $text = ucp_preg_replace(
                    '%(?<=[>\s])' . preg_quote($smiley_text, '%') . '(?=[^\p{L}\p{N}])%um',
                    '<img src="' . pun_htmlspecialchars(get_base_url(true) . '/img/smilies/' . $smiley_img)
                        . '" width="15" height="15" alt="' . substr($smiley_img, 0, strrpos($smiley_img, '.'))
                        . '" />',
                    $text);
        }

        return substr($text, 1, -1);
    }

    private static function parse_text(&$tree, &$errors, $context = null) {
        $close_tag = is_null($context) ? null : '[/' . $context . ']';
        // text within [code] should not be touched
        $preformatted_context = !is_null($context) && strcasecmp($context, 'code') === 0;

        while (self::$i < self::$limit) {
            $char = self::$chars[self::$i];
            if ($char === '[' && self::$i + 2 < self::$limit) {
                if (!$preformatted_context && self::$chars[self::$i + 1] !== '/') {
                    self::dump_string_buffer($tree);
                    self::parse_tag($tree, $errors, $context);
                    continue;
                } else if (!is_null($context) && self::matches_close_tag($close_tag)) {
                    return self::dump_string_buffer($tree);
                }
            }
            self::$string_buffer[] = $char;
            self::$i++;
        }
        self::dump_string_buffer($tree);

        if (!is_null($context))
            $errors[] = 'missing close tag: ' . $close_tag;
    }

    private static function parse_tag(&$tree, &$errors, $context) {
        // consume [
        self::$i++;

        while (self::$i < self::$limit && preg_match("%[a-zA-Z\*]%s", self::$chars[self::$i])) {
            self::$string_buffer[] = self::$chars[self::$i++];
        }
        $tag = self::get_string_buffer();

        // if it's not a valid tag/attribute presence combination,
        // refund before attempting to parse the attribute
        if (self::$i >= self::$limit
            || !self::is_valid_tag($tag, ($has_attr = self::$chars[self::$i] === '=')))
            return self::dump_string($tree, '[' . $tag);

        // if the attribute value is invalid, refund
        $attr = $has_attr ? self::parse_attr($errors) : null;
        if ($has_attr && !self::is_valid_attr($tag, $attr))
            return self::dump_string($tree, '[' . $tag . '=' . $attr);

        // must find ] or else it's not actually a tag
        if (self::$i >= self::$limit || self::$chars[self::$i] !== ']')
            return self::dump_string($tree, '[' . $tag . ($has_attr ? '=' . $attr : ''));

        // consume ]
        self::$i++;

        $node = new SyntaxTreeTagNode($tag, $attr, array());
        self::parse_text($node->children, $errors, $tag);
        self::dump_node($tree, $node);

        // consume close tag (control will not return from `parse_text` unless it's found)
        self::$i += strlen($tag) + 3;

        // TODO: validate tag nesting before surrendering control
    }

    private static function parse_attr(&$errors) {
        // consume =
        self::$i++;

        // this parser is now powerful enough to supported escaped
        // nested quotes, but that's annoying so we won't bother for now
        $quotes = array('"', '\'', '`');
        $terminal = ']';
        $quoted = false;
        if (self::$i < self::$limit && in_array(self::$chars[self::$i], $quotes)) {
            $terminal = self::$chars[self::$i++];
            $quoted = true;
            self::$string_buffer[] = $terminal;
        }

        while (self::$i < self::$limit && self::$chars[self::$i] !== $terminal) {
            if (self::$chars[self::$i] === "\n") break;
            self::$string_buffer[] = self::$chars[self::$i++];
        }

        if ($quoted && self::$i < self::$limit && self::$chars[self::$i] === $terminal)
            self::$string_buffer[] = self::$chars[self::$i++];

        return self::get_string_buffer();
    }

    private static function matches_close_tag($expected_tag) {
        return self::$i + strlen($expected_tag) <= self::$limit &&
            strcasecmp(
                substr(self::$chars, self::$i, strlen($expected_tag)),
                $expected_tag) === 0;
    }

    private static function is_valid_tag(&$tag, $has_attr) {
        $lc_tag = strtolower($tag);
        return in_array($lc_tag, self::$all_tags)
            && (!$has_attr || in_array($lc_tag, self::$attr_permitted))
            && ($has_attr || !in_array($lc_tag, self::$attr_required));
    }

    private static function is_valid_attr(&$tag, &$attr) {
        return strcasecmp($tag, 'list') !== 0
            || in_array($attr, array('1', 'a', '*'));
    }

    private static function get_string_buffer() {
        if (empty(self::$string_buffer)) return '';
        $string = implode('', self::$string_buffer);
        self::$string_buffer = array();
        return $string;        
    }

    private static function dump_node(&$tree, &$node) {
        if ($node instanceof SyntaxTreeTextNode) {
            if (empty($node->text))
                return;

            if (!empty($tree) && (($last = $tree[count($tree) - 1]) instanceof SyntaxTreeTextNode)) {
                $last->text .= $node->text;
                return;
            }
        }
        // skip empty bbcode
        else if (empty($node->children))
            return;

        $tree[] = $node;
    }

    private static function dump_string(&$tree, $string) {
        $node = new SyntaxTreeTextNode($string);
        self::dump_node($tree, $node);
    }

    private static function dump_string_buffer(&$tree) {
        if (!empty(self::$string_buffer)) {
            $string = implode('', self::$string_buffer);
            self::$string_buffer = array();
            self::dump_string($tree, $string);
        }
    }
}

// TODO: test script, delete
$pun_config = ['o_smilies' => '1', 'o_make_links' => '0', 'o_censoring' => '0',
                'p_message_bbcode' => '1', 'o_base_url' => 'https://blast.thegt.org'];
$pun_user = ['show_img' => '1', 'show_smilies' => '1'];
$lang_common = ['wrote' => 'wrote:'];
require('./utf8/utf8.php');
require('./functions.php');
$errors = [];
$tree = BBCodeParser::buildSyntaxTree(file_get_contents($argv[1]), $errors);
echo BBCodeParser::generateHTML($tree);
echo PHP_EOL;
