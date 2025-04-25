<?php
/**
 * Copyright (C) 2025 GeonoTRON2000
 * based on code by FluxBB copyright (C) 2008-2012 FluxBB
 * based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

 // Make sure no one attempts to run this script "directly"
if (!defined('PUN'))
   exit;

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

    private static array $nesting_limits;
    private static $block_tags = array('quote', 'code', 'list', 'h', '*');
    private static $link_tags = array('url', 'email', 'topic', 'post', 'forum', 'user');
    private static $id_tags = array('topic', 'post', 'forum', 'user');
    private static $context_limit_bbcode = array(
        '*' 	=> array('b', 'i', 'u', 's', 'c', 'ins', 'del', 'em', 'color', 'colour', 'url',
                            'email', 'list', 'img', 'code', 'topic', 'post', 'forum', 'user'),
        'list' 	=> array('*'),
        'url' 	=> array('img'),
        'email' => array('img'),
        'topic' => array('img'),
        'post'  => array('img'),
        'forum' => array('img'),
        'user'  => array('img'),
        'img' 	=> array(),
        'h'		=> array('b', 'i', 'u', 's', 'c', 'ins', 'del', 'em', 'color', 'colour', 'url',
                            'email', 'topic', 'post', 'forum', 'user'));
    private static $context_validate_interior = 
        array('img', 'url', 'email', 'topic', 'post', 'forum', 'user', 'code');
    private static $context_validate_attr =
        array('url', 'email', 'topic', 'post', 'forum', 'user', 'color', 'colour', 'list');

    private static int $i;
    private static int $limit;
    private static int $mode;
    private static string $chars;
    private static array $string_buffer;
    private static bool $is_signature;
    private static bool $show_smilies;

    public static function generateHTML(
        &$tree, $is_signature = false, $show_smilies = true) {
        self::$is_signature = $is_signature;
        self::$show_smilies = $show_smilies;

        return self::format_html(self::generate_code($tree));
    }

    public static function generateFallback(
        $text, $is_signature = false, $show_smilies = true) {
        self::$is_signature = $is_signature;
        self::$show_smilies = $show_smilies;

        return self::format_html(self::generate_text($text));
    }

    private static function format_html($text) {
        $text = '<p>' . pun_trim($text) . '</p>';
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

        self::$nesting_limits = array(
            'quote' => $pun_config['o_quote_depth'],
            'list' => 5,
            '*' => 5);

        self::$chars = pun_trim($text);

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
            return self::generate_text($node->text);
        } else if ($node instanceof SyntaxTreeTagNode) {
            switch ($tag = $node->tag) {
                case 'img':
                    return self::generate_img_tag($node);
                case 'c':
                case 'code':
                    return self::generate_code_tag($node, $tag === 'code');
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

    private static function generate_code_tag(&$node, $code_block) {
        $code = pun_htmlspecialchars(pun_trim(self::node_interior_as_text($node), "\n\r"));

        $num_lines = substr_count($code, "\n");
        if ($code_block)
            return '</p><div class="codebox"><pre'
                . (($num_lines > 28) ? ' class="vscroll"' : '')
                . '><code>' . $code .'</code></pre></div><p>';
        else
            return '<code class="code">' . $code . '</code>';
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
                $custom_url ? self::generate_text($child->text, false) : null);
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

    private static function generate_text($text, $ctx_replace_links = true) {
        global $pun_config, $pun_user;

        // Censoring skips code tags, but maybe that's a good thing
        // i.e. `public static cl*** BBCodeParser {}`
        if ($pun_config['o_censoring'] === '1')
    		$text = censor_words($text);

        $text = pun_htmlspecialchars($text);

        if ($ctx_replace_links && $pun_config['o_make_links'] === '1'
                // TODO: this will be a bug, it needs to be the
                // poster's group not the viewer's
                && $pun_user['g_post_links'] === '1')
            $text = self::replace_links($text);

        if (self::$show_smilies)
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
        return preg_replace_callback(
            '%(https?://|www\.)([a-z0-9\-]+\.)+([a-z0-9]{2,})(\/[a-z0-9\-\.\/]*(\?[a-z0-9\-_\.]+(=[a-z0-9\-_\.]*)?(&amp;[a-z0-9\-_\.]+(=[a-z0-9\-_\.]*)?)*)?(\#[a-z0-9\-_]*)?)?%i',
            function($matches) {
                $url = strcasecmp(substr($matches[0], 0, 4), 'http') !== 0
                    ? 'https://' . $matches[0] : $matches[0];
                return '<a href="' . $url . '" rel="nofollow">'
                    . self::safe_truncate_url($matches[0]) . '</a>';
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

    // true = pass validations and render
    // false = pass validations but do not render this tag
    // append to $errors = fail validations
    private static function validate_tag_rules(&$tag, &$errors, &$context) {
        global $pun_user, $lang_common;
        $depth = array_count_values($context);
        $parent = empty($context) ? null : $context[count($context) - 1];

        if (isset($depth[$tag]) && !isset(self::$nesting_limits[$tag]))
            $errors[] = sprintf(
                $lang_common['BBCode error invalid self-nesting'], $tag);
        else if (isset($depth[$tag])
                && $depth[$tag] >= self::$nesting_limits[$tag])
            $errors[] = sprintf(
                $lang_common['BBCode error nesting depth'],
                $tag,
                self::$nesting_limits[$tag]);
        else if ($pun_user['g_post_links'] !== '1' && $tag === 'url')
            $errors[] = $lang_common['BBCode error tag url not allowed'];
        else if ($parent = self::invalid_tag_nesting($tag, $context))
            $errors[] = sprintf(
                $lang_common['BBCode error invalid nesting'], $tag, $parent);
    }

    private static function invalid_tag_nesting(&$tag, &$context) {
        $block_tag = in_array($tag, self::$block_tags);
        foreach ($context as $parent_tag) {
            if (isset(self::$context_limit_bbcode[$parent_tag])
                    && !in_array($tag, self::$context_limit_bbcode[$parent_tag]))
                return $parent_tag;
            if ($block_tag && !in_array($parent_tag, self::$block_tags))
                return $parent_tag;
        }
        return false;
    }

    private static function validate_tag_attr(&$tag, &$attr, &$errors) {
        global $lang_common;
        if (!in_array($tag, self::$context_validate_attr))
            return;

        if (empty($attr))
            $errors[] = sprintf($lang_common['BBCode error empty attribute'], $tag);
        else if ($tag === 'list' && !in_array($attr, array('1', 'a', '*')))
            $errors[] = $lang_common['BBCode error invalid list attribute'];
        else if ($tag === 'email' && !self::validate_email($attr))
            $errors[] = sprintf($lang_common['BBCode error invalid attribute'], $tag);
        else if ($tag === 'url' && !self::validate_url($attr))
            $errors[] = sprintf($lang_common['BBCode error invalid attribute'], $tag);
        else if (($tag === 'color' || $tag === 'colour') && !self::validate_color($attr))
            $errors[] = sprintf($lang_common['BBCode error invalid attribute'], $tag);
        else if (in_array($tag, self::$id_tags) && intval($attr) < 1)
            $errors[] = sprintf($lang_common['BBCode error invalid attribute'], $tag);
    }

    private static function validate_tag_content(&$node, &$errors) {
        global $lang_common;

        if (empty($node->children))
            $errors[] = $lang_common['BBCode error empty tag'];

        // link tags with the link specified can have literal DOM trees inside,
        // we could care less at this stage (nesting rules will catch shenanigans)
        if (!in_array($node->tag, self::$context_validate_interior)
                || (!is_null($node->attribute) && in_array($node->tag, self::$link_tags)))
            return;

        $child = $node->children[0];
        if (count($node->children) > 1)
            $errors[] = sprintf(
                $lang_common['BBCode error unwanted bbcode'], $node->tag);
        else if (($node->tag === 'c' || $node->tag === 'code')
                    && !self::text_for_validation($child))
            // can only show up if we screw up somehow but throwing is better than XSS
            $errors[] = sprintf(
                $lang_common['BBCode error unwanted bbcode'], $node->tag);
        else if ($node->tag === 'img'
                    && !self::validate_url(self::text_for_validation($child)))
            $errors[] = $lang_common['BBCode error invalid img'];
        else if ($node->tag === 'url'
                    && !self::validate_url(self::text_for_validation($child))
                    && !self::validate_is_img($child))
            $errors[] = $lang_common['BBCode error invalid url'];
        else if ($node->tag === 'email'
                    && !self::validate_email(self::text_for_validation($child)))
            $errors[] = $lang_common['BBCode error invalid email'];
        else if (in_array($node->tag, self::$id_tags)
                    && intval(self::text_for_validation($child)) < 1)
            $errors[] = sprintf($lang_common['BBCode error invalid id'], $node->tag);
    }

    private static function text_for_validation(&$node) {
        if ($node instanceof SyntaxTreeTextNode)
            return $node->text;

        return false;
    }

    private static function validate_is_img(&$node) {
        return ($node instanceof SyntaxTreeTagNode) && $node->tag === 'img';
    }

    private static function validate_email($email) {
        // FluxBB doesn't even validate this much
        return $email !== false && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function validate_url($url) {
        // this is literally more than FluxBB does...
        return $url !== false && preg_match('%^[^\[\]\(\)]+$%s', $url) !== false;
    }

    private static function validate_color($color) {
        return $color !== false
            && preg_match(
                '%^[a-zA-Z]{3,20}|\#[0-9a-fA-F]{6}|\#[0-9a-fA-F]{3}$%s', $color) !== false;
    }

    private static function parse_text(&$tree, &$errors, $context = array()) {
        global $lang_common;

        if (!empty($context)) {
            $parent_tag = $context[count($context) - 1];
            $close_tag = '[/' . $parent_tag . ']';
        }
        // text within [code] should not be touched
        $preformatted_context = !empty($context) && strcasecmp($parent_tag, 'code') === 0;

        while (self::$i < self::$limit) {
            $char = self::$chars[self::$i];
            if ($char === '[' && self::$i + 2 < self::$limit) {
                if (!$preformatted_context && self::$chars[self::$i + 1] !== '/') {
                    self::accept_string_buffer($tree);
                    self::parse_tag($tree, $errors, $context);
                    continue;
                } else if (!empty($context) && self::matches_close_tag($close_tag)) {
                    return self::accept_string_buffer($tree);
                }
            }
            self::$string_buffer[] = $char;
            self::$i++;
        }
        self::accept_string_buffer($tree);

        if (!empty($context) && empty($errors))
            $errors[] =
                sprintf($lang_common['BBCode error no closing tag'], $parent_tag);
    }

    private static function parse_tag(&$tree, &$errors, $context) {
        // consume [
        self::$i++;

        while (self::$i < self::$limit && preg_match("%[a-zA-Z\*]%s", self::$chars[self::$i])) {
            self::$string_buffer[] = self::$chars[self::$i++];
        }
        $tag = self::get_string_buffer();
        $lc_tag = strtolower($tag);

        // if it's not a valid tag/attribute presence combination,
        // refund before attempting to parse the attribute
        if (self::$i >= self::$limit
            || !self::is_valid_tag($lc_tag, ($has_attr = self::$chars[self::$i] === '=')))
            return self::accept_string($tree, '[' . $tag);

        $attr = $has_attr ? self::parse_attr($errors) : null;

        // must find ] or else it's not actually a tag
        if (self::$i >= self::$limit || self::$chars[self::$i] !== ']')
            return self::accept_string($tree, '[' . $tag . ($has_attr ? '=' . $attr : ''));

        // validate attribute format and tag nesting
        if ($has_attr)
            self::validate_tag_attr($lc_tag, $attr, $errors);
        self::validate_tag_rules($lc_tag, $errors, $context);

        if (!empty($errors)) {
            self::$i = self::$limit;
            return;
        }

        // passed validations, it's a tag--consume ]
        self::$i++;

        $node = new SyntaxTreeTagNode($lc_tag, $attr, array());
        $child_context = $context;
        $child_context[] = $lc_tag;
        self::parse_text($node->children, $errors, $child_context);

        self::validate_tag_content($node, $errors);
        if (!empty($errors)) {
            self::$i = self::$limit;
            return;
        }

        // passed all validations, it's valid--consume close tag and add to tree
        self::$i += strlen($tag) + 3;
        self::accept_node($tree, $node);
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
        return in_array($tag, self::$all_tags)
            && (!$has_attr || in_array($tag, self::$attr_permitted))
            && ($has_attr || !in_array($tag, self::$attr_required));
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

    private static function accept_node(&$tree, &$node) {
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

    private static function accept_string(&$tree, $string) {
        $node = new SyntaxTreeTextNode($string);
        self::accept_node($tree, $node);
    }

    private static function accept_string_buffer(&$tree) {
        if (!empty(self::$string_buffer)) {
            $string = implode('', self::$string_buffer);
            self::$string_buffer = array();
            self::accept_string($tree, $string);
        }
    }
}

// exports
function validate_bbcode($text, &$errors) {
    BBCodeParser::buildSyntaxTree($text, $errors);
}

function parse_message($text, $hide_smilies) {
    global $pun_config, $pun_user;

    $bbcode = $pun_config['p_message_bbcode'] === '1'
                && strpos($text, '[') !== false && strpos($text, ']') !== false;
    $smilies = $pun_config['o_smilies'] === '1'
                && $pun_user['show_smilies'] === '1' && $hide_smilies !== '1';

    if ($bbcode) {
        $errors = array();
        $tree = BBCodeParser::buildSyntaxTree($text, $errors);
        
        if (empty($errors))
            return BBCodeParser::generateHTML($tree, false, $smilies);
    }

    return BBCodeParser::generateFallback($text, false, $smilies);
}

function parse_signature($text) {
    global $pun_config, $pun_user;

    $bbcode = $pun_config['p_sig_bbcode'] === '1'
                && strpos($text, '[') !== false && strpos($text, ']') !== false;
    $smilies = $pun_config['o_smilies_sig'] === '1' && $pun_user['show_smilies'] === '1';

    if ($bbcode) {
        $errors = array();
        $tree = BBCodeParser::buildSyntaxTree($text, $errors);
        
        if (empty($errors))
            return BBCodeParser::generateHTML($tree, true, $smilies);
    }

    return BBCodeParser::generateFallback($text, true, $smilies);
}
