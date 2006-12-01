<?php # $Id$

if (IN_serendipity !== true) {
    die ("Don't hack!");
}


// Probe for a language include with constants. Still include defines later on, if some constants were missing
$probelang = dirname(__FILE__) . '/' . $serendipity['charset'] . 'lang_' . $serendipity['lang'] . '.inc.php';
if (file_exists($probelang)) {
    include $probelang;
}

include dirname(__FILE__) . '/lang_en.inc.php';

class serendipity_event_mailer extends serendipity_event
{
    var $title = PLUGIN_EVENT_MAILER_NAME;
    var $data  = array();

    function introspect(&$propbag)
    {
        global $serendipity;

        $propbag->add('name',          PLUGIN_EVENT_MAILER_NAME);
        $propbag->add('description',   PLUGIN_EVENT_MAILER_DESC);
        $propbag->add('stackable',     false);
        $propbag->add('author',        'Sebastian Nohn, Kristian Koehntopp, Garvin Hicking');
        $propbag->add('version',       '1.47');
        $propbag->add('requirements',  array(
            'serendipity' => '0.8',
            'smarty'      => '2.6.7',
            'php'         => '4.1.0'
        ));
        $propbag->add('event_hooks',    array(
            'backend_publish' => true,
            'backend_display' => true,
        ));
        $propbag->add('groups', array('FRONTEND_ENTRY_RELATED'));

        $config = array('what', 'mailto', 'includelink', 'striptags', 'convertp');
        $propbag->add('configuration', $config);
    }

    function performConfig(&$bag) {
        if (is_object($bag)) {
            $conf = $bag->get('configuration');
        }

        $this->data['categories'] = serendipity_fetchCategories('all');
        if (!is_array($this->data['categories'])) {
            return false;
        }

        foreach($this->data['categories'] AS $cat) {
            $conf[] = 'category_' . $cat['categoryid'];
            $this->data['cat'][$cat['categoryid']] = $cat;
        }

        if (is_object($bag)) {
            $bag->add('configuration', $conf);
        }
    }

    function introspect_config_item($name, &$propbag)
    {
        if (preg_match('@^category_([0-9]+)$@imsU', $name, $catid)) {
            $parent = '';
            if ($this->data['cat'][$catid[1]]['parentid'] > 0) {
                $parent = " '" . $this->data['cat'][$this->data['cat'][$catid[1]]['parentid']]['category_name'] . "' -";
            }
            $propbag->add('type',        'string');
            $propbag->add('name',        CATEGORY . $parent . " '"  . $this->data['cat'][$catid[1]]['category_name'] . "'");
            $propbag->add('description', PLUGIN_EVENT_MAILER_RECIPIENTDESC);
            $propbag->add('default', '');
            return true;
        }

        switch($name) {
            case 'what':
                $propbag->add('type',        'select');
                $propbag->add('name',        CONTENT);
                $propbag->add('description', '');
                $propbag->add('select_values',
                    array(
                        'all'      => ENTRY_BODY . ' + ' . EXTENDED_BODY,
                        'body'     => ENTRY_BODY,
                        'extended' => EXTENDED_BODY,
                        'none'     => NONE
                    )
                );
                $propbag->add('default',     'all');
                break;

            case 'mailto':
                $propbag->add('type',        'string');
                $propbag->add('name',        PLUGIN_EVENT_MAILER_RECIPIENT);
                $propbag->add('description', PLUGIN_EVENT_MAILER_RECIPIENTDESC);
                $propbag->add('default', '');
                break;

            case 'includelink':
                $propbag->add('type',        'boolean');
                $propbag->add('name',        PLUGIN_EVENT_MAILER_LINK);
                $propbag->add('description', PLUGIN_EVENT_MAILER_LINKDESC);
                $propbag->add('default',     'false');
                break;

            case 'striptags':
                $propbag->add('type',        'boolean');
                $propbag->add('name',        PLUGIN_EVENT_MAILER_STRIPTAGS);
                $propbag->add('description', PLUGIN_EVENT_MAILER_STRIPTAGSDESC);
                $propbag->add('default',     'false');
                break;

            case 'convertp':
                $propbag->add('type',        'boolean');
                $propbag->add('name',        PLUGIN_EVENT_MAILER_CONVERTP);
                $propbag->add('description', PLUGIN_EVENT_MAILER_CONVERTPDESC);
                $propbag->add('default',     'false');
                break;

            default:
                break;
        }

        return true;
    }

    function generate_content(&$title) {
        $title = $this->title;
    }

    function event_hook($event, &$bag, &$eventData) {
        global $serendipity;

        $hooks = &$bag->get('event_hooks');

        if (isset($hooks[$event])) {
            switch($event) {
                case 'backend_display':
                    if (isset($serendipity['POST']['properties']['mailto'])) {
                        $mailto = $serendipity['POST']['properties']['mailto'];
                    } else {
                        $mailto = $this->get_config('mailto');
                    }

?>
                    <fieldset style="margin: 5px">
                        <legend><?php echo PLUGIN_EVENT_MAILER_NAME; ?></legend>
                            <input type="checkbox" name="serendipity[properties][sendentry]" id="properties_sendentry" value="true" checked="checked" />
                            <label title="<?php echo PLUGIN_EVENT_MAILER_SENDING; ?>" for="properties_sendentry">&nbsp;<?php echo PLUGIN_EVENT_MAILER_ISTOSENDIT; ?></label><br />
                            <label title="<?php echo PLUGIN_EVENT_MAILER_RECIPIENT; ?>" for="properties_mailto">&nbsp;<?php echo PLUGIN_EVENT_MAILER_RECIPIENTS; ?>&nbsp;&nbsp;</label>&nbsp;
                            <input type="text" name="serendipity[properties][mailto]" id="properties_mailto" value="<?php echo htmlspecialchars($mailto); ?>" />
                    </fieldset>
<?php
                    break;

                case 'backend_publish':
                    if (isset($serendipity['POST']['properties']) && !isset($serendipity['POST']['properties']['sendentry'])) {
                        echo PLUGIN_EVENT_MAILER_NOTSENDDECISION . '<br />';
                    } else {
                        $mails = explode(' ', $this->get_config('mailto'));
                        $to = array();
                        foreach($mails AS $mailto) {
                            $mailto = trim($mailto);
                            if (!empty($mailto)) {
                                $to[] = $mailto;
                            }
                        }

                        $this->performConfig($to);
                        if (is_array($this->data['cat'])) {
                            $selected = array();
                            if (is_array($eventData['categories'])) {
                                foreach($eventData['categories'] AS $idx => $cid) {
                                    $selected[$cid] = true;
                                }
                            }

                            foreach($this->data['cat'] AS $cid => $cat) {
                                $mailto = trim($this->get_config('category_' . $cid));

                                if (!empty($mailto) && isset($selected[$cid])) {
                                    $tos = explode(' ', $mailto);
                                    foreach($tos AS $mailtopart) {
                                        $to[] = trim($mailtopart);
                                    }
                                }
                            }
                        }


                        $mail = array(
                            'subject' => $eventData['title'],
                            'body'    => $eventData['body'] . $eventData['extended'],
    //                      'from'    => $serendipity['blogTitle'] . ' - ' . $eventData['author'] . ' <' . $serendipity['serendipityEmail'] . '>'
                            'from'    => $serendipity['serendipityEmail']
                        );

                        switch($this->get_config('what')) {
                            case 'all':
                                $mail['body']     = $eventData['body'] . $eventData['extended'];
                            case 'body':
                                $mail['body']     = $eventData['body'];
                                break;
                            case 'extended':
                                $mail['body']     = $eventData['extended'];
                                break;
                            case 'none':
                                $mail['body']     = '';
                        }

                        if (isset($serendipity['POST']['properties']['mailto'])) {
                            $mails = explode(' ', $serendipity['POST']['properties']['mailto']);
                            foreach($mails as $mailto) {
                                $mailto = trim($mailto);
                                if (!in_array($mailto, $to)) {
                                    $to[] = $mailto;
                                }
                            }
                        }

                        if (serendipity_db_bool($this->get_config('convertp', false)) == true) {
                            $mail['body'] = str_replace('</p>', "</p>\n", $mail['body']);
                        }

                        if (serendipity_db_bool($this->get_config('striptags', false)) == true) {
                            $mail['body'] = preg_replace('�<a[^>]+href=["\']([^"\']*)["\'][^>]*>([^<]*)</a>�i', "$2 [$1]", $mail['body']);
                            $mail['body'] = preg_replace('�<img[^>]+src=["\']([^"\']*)["\'][^>]*>�i', "[" . IMAGE . ": $1]", $mail['body']);
                            $mail['body'] = strip_tags($mail['body']);
                        }

                        if (serendipity_db_bool($this->get_config('includelink', false)) == true) {
                            $mail['body'] = serendipity_archiveURL($eventData['id'], $eventData['title'], 'baseURL', true, array('timestamp' => $eventData['timestamp'])) . "\n\n" . $mail['body'];
                        }

                        foreach($to AS $mailto) {
                            if (!empty($mailto)) {
                                echo htmlspecialchars($mailto) . '...<br />';
                                serendipity_sendMail($mailto, $mail['subject'], $mail['body'], $mail['from']);
                            }
                        }
                    }
                    return true;
                    break;

                default:
                    return false;
                    break;
            }
        } else {
            return false;
        }
    }
}

/* vim: set sts=4 ts=4 expandtab : */
