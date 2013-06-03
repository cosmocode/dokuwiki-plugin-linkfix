<?php
/**
 * DokuWiki Plugin rebase (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <andi@splitbrain.org>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_LF')) define('DOKU_LF', "\n");
if(!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');

require_once DOKU_PLUGIN . 'admin.php';

class admin_plugin_rebase extends DokuWiki_Admin_Plugin {

    private $searchin = '';
    private $filter = '';
    private $changefrom = '';
    private $changeto = '';
    private $dryrun = false;

    public function getMenuSort() {
        return 3000;
    }

    public function handle() {
        if(isset($_REQUEST['searchin'])) $this->searchin = cleanID($_REQUEST['searchin']);
        if(isset($_REQUEST['filter'])) $this->filter = cleanID($_REQUEST['filter']);
        if(isset($_REQUEST['changefrom'])) $this->changefrom = cleanID($_REQUEST['changefrom']);
        if(isset($_REQUEST['changeto'])) $this->changeto = cleanID($_REQUEST['changeto']);
        if(isset($_REQUEST['dryrun'])) $this->dryrun = (bool) $_REQUEST['dryrun'];
    }

    public function html() {

        if(!empty($_REQUEST['go']) && checkSecurityToken()) {
            echo "<h3>Processing, do not interrupt...</h3>";
            tpl_flush();

            ignore_user_abort(true);
            set_time_limit(0);
            $this->execute();

            echo "<h3>Processing done.</h3>";

            if($this->dryrun) {
                echo '<a href="' . wl(
                    '', array(
                             'do' => 'admin',
                             'page' => 'rebase',
                             'searchin' => $this->searchin,
                             'filter' => $this->filter,
                             'changefrom' => $this->changefrom,
                             'changeto' => $this->changeto,
                             'dryrun' => 0,
                             'go' => 'go',
                             'sectok' => getSecurityToken()
                        )
                ) . '">rerun the process for real (NO simulation!)</a>';
            }
            tpl_flush();
        } else {

            echo '<p>Don\'t use this unless you have any idea of what this is doing (eg. are a CosmoCode employee)</p>';

            $form = new Doku_Form(array());
            $form->addHidden('go', 'go');
            $form->startFieldset('Configure');
            $form->addElement(form_makeTextField('searchin', '', 'Search in', '', 'block'));
            $form->addElement(form_makeTextField('filter', '', 'Look for links to this namespace', '', 'block'));
            $form->addElement(form_makeTextField('changefrom', '', 'Change from this prefix namespace', '', 'block'));
            $form->addElement(form_makeTextField('changeto', '', 'Change to this prefix namespace', '', 'block'));
            $form->addElement(form_makeCheckboxField('dryrun', '1', 'Simulate only', '', 'block', array('checked' => 'checked')));
            $form->addElement(form_makeButton('submit', ''));
            $form->printForm();
        }
    }

    /**
     * Search for all pages in the set namespace and update them
     */
    private function execute() {
        global $conf;

        $data = array();
        search(
            $data,
            $conf['datadir'],
            'search_allpages',
            array(
                 'depth' => 0,
                 'skipacl' => true,
                 'hash' => false,
            ),
            str_replace(':', '/', $this->searchin)
        );

        foreach($data as $file) {
            print '<b>' . hsc($file['id']) . "</b><br />";
            tpl_flush();
            $this->updatepage($file['id']);
        }
    }

    /**
     * Rewrite all links and media items in the given page
     *
     * @param string $currentpage the page to rewrite
     */
    private function updatepage($currentpage) {
        $currentns = getNS($currentpage);

        $text = rawWiki($currentpage);
        $crc = md5($text);
        $instructions = p_get_instructions($text);
        $instructions = array_reverse($instructions);

        foreach($instructions as $instruction) {
            if($instruction[0] == 'internallink' || $instruction[0] == 'internalmedia') {
                $type = substr($instruction[0], 8); //strip "internal"
                $link = $instruction[1][0];
                $pos = $instruction[2] - 1;

                while(
                    $text{$pos} == '[' ||
                    $text{$pos} == '{' ||
                    $text{$pos} == ' ' ||
                    $text{$pos} == "\t"
                ) {
                    $pos++;
                }

                $full = $link;
                $exists = false;
                if($type == 'page') {
                    resolve_pageid($currentns, $full, $exists);
                } else {
                    resolve_mediaid($currentns, $full, $exists);
                }

                if($this->filter && strpos($full, "$this->filter:") !== 0) {
                    // current link does not match filter, ignore
                    continue;
                }

                // create the new link
                $newlink = $this->changeLink($link, $full, $currentns);

                // replace the link
                if(($link != $newlink) && ($full != cleanID($newlink))) {
                    echo '&nbsp;&nbsp;&nbsp;' . hsc($link) . ' -> ' . hsc($newlink) . '<br />';

                    $text = substr($text, 0, $pos) .
                        $newlink . // new link
                        substr($text, $pos + strlen($link)); // continue after old link
                }

            }
            // everything else is ignored
        }
        if($crc == md5($text)) {
            echo '✗ page not changed<br />';
        } else {
            if($this->dryrun) {
                echo '✓ simulating, page changes not saved<br />';
            } else {
                saveWikiText($currentpage, $text, 'links rebased', true);
                echo '✓ new page version saved <br />';
            }
        }
        tpl_flush();
    }

    /**
     * Rewrite the given link according to the given settings
     *
     * @param string $link the current link as found in the page source
     * @param string $full the full, resolved version of the above link
     * @param string $currentNS the namespace of the page the link was found in
     * @return string the corrected link
     */
    private function changeLink($link, $full, $currentNS) {
        // make sure the prefix matches
        if($this->changefrom && strpos($full, "$this->changefrom:") !== 0) return $link;

        // strip prefix
        $new = substr($full, strlen($this->changefrom));

        // strip left over colon
        $new = ltrim($new, ':');

        // add prefix
        if($this->changeto) $new = $this->changeto . ':' . $new;

        // strip left over colon
        $new = ltrim($new, ':');

        // make absolute if needed
        if($currentNS && strpos($new, ':') === false) $new = ":$new";

        return $new;
    }

}

// vim:ts=4:sw=4:et:
