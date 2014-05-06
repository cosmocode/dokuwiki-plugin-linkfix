<?php
/**
 * DokuWiki Plugin linkfix (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <andi@splitbrain.org>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class admin_plugin_linkfix extends DokuWiki_Admin_Plugin {

    private $searchin = '';
    private $changefrom = '';
    private $changeto = '';
    private $dryrun = false;
    private $type = 'links';

    public function getMenuSort() {
        return 3000;
    }

    public function handle() {
        if(isset($_REQUEST['searchin'])) $this->searchin = cleanID($_REQUEST['searchin']);
        if(isset($_REQUEST['changefrom'])) $this->changefrom = $_REQUEST['changefrom'];
        if(isset($_REQUEST['changeto'])) $this->changeto = $_REQUEST['changeto'];
        if(isset($_REQUEST['type'])) $this->type = $_REQUEST['type'];
        if(isset($_REQUEST['dryrun'])) $this->dryrun = (bool) $_REQUEST['dryrun'];
    }

    public function html() {
        global $ID;

        if(!empty($_REQUEST['go']) && checkSecurityToken()) {
            echo '<h3>' . $this->getLang('processing') . '</h3>';
            tpl_flush();

            ignore_user_abort(true);
            set_time_limit(0);
            if($this->execute()) {
                echo "<h3>Processing done.</h3>";

                if($this->dryrun) {
                    echo '<a href="' . wl(
                            '', array(
                                     'do'         => 'admin',
                                     'page'       => 'linkfix',
                                     'searchin'   => $this->searchin,
                                     'changefrom' => $this->changefrom,
                                     'changeto'   => $this->changeto,
                                     'dryrun'     => 0,
                                     'go'         => 'go',
                                     'sectok'     => getSecurityToken()
                                )
                        ) . '">rerun the process for real (NO simulation!)</a>';
                }
                tpl_flush();
            }
        } else {

            echo $this->locale_xhtml('intro');

            $form = new Doku_Form(array('action' => wl()));
            $form->addHidden('go', 'go');
            $form->addHidden('page', 'linkfix');
            $form->addHidden('id', $ID);
            $form->startFieldset($this->getLang('menu'));
            $form->addElement(form_makeTextField('searchin', '', $this->getLang('searchin'), '', 'block'));
            $form->addElement(form_makeTextField('changefrom', '', $this->getLang('changefrom'), '', 'block'));
            $form->addElement(form_makeTextField('changeto', '', $this->getLang('changeto'), '', 'block'));
            $form->addElement(form_makeRadioField('type', 'links', $this->getLang('links'), '', 'block', array('checked' => 'checked')));
            $form->addElement(form_makeRadioField('type', 'media', $this->getLang('media'), '', 'block'));
            $form->addElement(form_makeCheckboxField('dryrun', '1', $this->getLang('dryrun'), '', 'block', array('checked' => 'checked')));
            $form->addElement(form_makeButton('submit', 'admin', $this->getLang('submit')));
            $form->printForm();
        }
    }

    /**
     * Search for all pages in the set namespace and update them
     *
     * @returns bool false if no correct namespace was given
     */
    private function execute() {
        global $conf;

        $searchin = str_replace(':', '/', $this->searchin);
        if(!is_dir($conf['datadir'] . '/' . $searchin)) {
            msg(sprintf($this->getLang('badnamespace'), hsc($this->searchin)), -1);
            return false;
        }

        // FIXME use index. When local link (not HTTP prefix) use metadata index, otherwise standard fulltext

        $data = array();
        search(
            $data,
            $conf['datadir'],
            'search_allpages',
            array(
                 'depth'   => 0,
                 'skipacl' => true,
                 'hash'    => false,
            ),
            $searchin
        );

        foreach($data as $file) {
            print $this->getLang('checking') . ' <b>' . hsc($file['id']) . "</b><br />";
            tpl_flush();
            $this->updatepage($file['id']);
        }

        return true;
    }

    /**
     * Rewrite all links and media items in the given page
     *
     * @param string $currentpage the page to rewrite
     */
    private function updatepage($currentpage) {
        $currentns = getNS($currentpage);

        $text         = rawWiki($currentpage);
        $crc          = md5($text);
        $instructions = p_get_instructions($text);
        $instructions = array_reverse($instructions);

        foreach($instructions as $instruction) {
            if(
                ($this->type == 'links' && ($instruction[0] == 'internallink' || $instruction[0] == 'externallink')) ||
                ($this->type == 'media' && ($instruction[0] == 'internalmedia' || $instruction[0] == 'externalmedia'))
            ) {
                $link = $instruction[1][0];
                $pos  = $instruction[2] - 1;

                while(
                    $text{$pos} == '[' ||
                    $text{$pos} == '{' ||
                    $text{$pos} == ' ' ||
                    $text{$pos} == "\t"
                ) {
                    $pos++;
                }

                $full   = $link;
                $exists = false;
                if($this->type == 'links') {
                    resolve_pageid($currentns, $full, $exists);
                } else {
                    resolve_mediaid($currentns, $full, $exists);
                }

                // create the new link
                $newlink = $this->changeLink($link, $full, $currentns);

                // replace the link
                if(($link != $newlink) && ($full != cleanID($newlink))) {
                    echo '&nbsp;&nbsp;&nbsp;' . hsc($link) . ' → ' . hsc($newlink) . '<br />';

                    $text = substr($text, 0, $pos) .
                        $newlink . // new link
                        substr($text, $pos + strlen($link)); // continue after old link
                }

            }
            // everything else is ignored
        }
        if($crc == md5($text)) {
            echo '✗ '.$this->getLang('fail').'<br />';
        } else {
            if($this->dryrun) {
                echo '✓ '.$this->getLang('successdry').'<br />';
            } else {
                saveWikiText($currentpage, $text, $this->getLang('summary'), true);
                echo '✓ '.$this->getLang('success').'<br />';
            }
        }
        tpl_flush();
    }

    /**
     * Rewrite the given link according to the given settings
     *
     * @param string $link      the current link as found in the page source
     * @param string $full      the full, resolved version of the above link
     * @param string $currentNS the namespace of the page the link was found in
     * @return string the corrected link
     */
    private function changeLink($link, $full, $currentNS) {
        // make sure the prefix matches
        if($this->changefrom && strpos($full, "$this->changefrom") !== 0) return $link;

        // strip prefix
        $new = substr($full, strlen($this->changefrom));

        // add prefix
        $new = $this->changeto . $new;

        // strip left over colons
        $new = ltrim($new, ':');

        // make absolute if needed
        if($currentNS && strpos($new, ':') === false) $new = ":$new";

        return $new;
    }

}

// vim:ts=4:sw=4:et:
