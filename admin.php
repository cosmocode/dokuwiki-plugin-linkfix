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

    protected $searchin = '';
    protected $changefrom = '';
    protected $changeto = '';
    protected $dryrun = false;
    protected $type = 'links';
    protected $isextern = false;

    public function getMenuSort() {
        return 3000;
    }

    public function handle() {
        global $INPUT;

        $this->searchin   = cleanID($INPUT->str('searchin'));
        $this->changefrom = $INPUT->str('changefrom');
        $this->changeto   = $INPUT->str('changeto');
        $this->type       = $INPUT->valid('type', array('links', 'media'), 'links');
        $this->dryrun     = $INPUT->bool('dryrun');
        $this->isextern   = preg_match('/^\w+:\/\//i', $this->changefrom);
    }

    public function html() {
        global $ID;
        global $INPUT;

        if($INPUT->has('go') && checkSecurityToken()) {
            echo '<h3>' . $this->getLang('processing') . '</h3>';
            tpl_flush();

            ignore_user_abort(true);
            set_time_limit(0);
            if($this->execute()) {
                echo '<h3>'.$this->getLang('processingdone').'</h3>';

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
                        ) . '">'.$this->getLang('rerunhot').'</a>';
                }
                tpl_flush();
            }
        } else {

            echo $this->locale_xhtml('intro');

            $form = new Doku_Form(array('action' => wl(), 'class' => 'plugin_linkfix'));
            $form->addHidden('go', 'go');
            $form->addHidden('page', 'linkfix');
            $form->addHidden('id', $ID);
            $form->startFieldset($this->getLang('menu'));
            $form->addElement(form_makeTextField('searchin', '', $this->getLang('searchin'), '', 'block'));
            $form->addElement(form_makeTextField('changefrom', '', $this->getLang('changefrom'), '', 'block'));
            $form->addElement(form_makeTextField('changeto', '', $this->getLang('changeto'), '', 'block'));
            $form->addElement(form_makeRadioField('type', 'links', $this->getLang('links'), '', 'block tick', array('checked' => 'checked')));
            $form->addElement(form_makeRadioField('type', 'media', $this->getLang('media'), '', 'block tick'));
            $form->addElement(form_makeCheckboxField('dryrun', '1', $this->getLang('dryrun'), '', 'block tick', array('checked' => 'checked')));
            $form->addElement(form_makeButton('submit', 'admin', $this->getLang('submit')));
            $form->printForm();
        }
    }

    /**
     * Search for all pages in the set namespace and update them
     *
     * @returns bool false if no correct namespace was given
     */
    protected function execute() {
        global $conf;

        // make sure the given search namespace exists
        $searchin = str_replace(':', '/', $this->searchin);
        if(!is_dir($conf['datadir'] . '/' . $searchin)) {
            msg(sprintf($this->getLang('badnamespace'), hsc($this->searchin)), -1);
            return false;
        }

        // use indexer to find all possibly affected pages
        if($this->isextern) {
            $null = '';
            $data = ft_pageSearch('"' . $this->changefrom . '"*', $null);
            $data = array_keys($data);
        } elseif($this->type == 'media') {
            $query = $this->changefrom . '*';
            $data  = idx_get_indexer()->lookupKey('relation_media', $query);
        } else {
            $query = $this->changefrom . '*';
            $data  = idx_get_indexer()->lookupKey('relation_references', $query);
        }
        $data = array_unique($data);

        $len = strlen($this->searchin);
        foreach($data as $id) {

            // skip everything that's not in the wanted namespace
            if($len && substr($id, 0, $len + 1) != $this->searchin . ':') continue;
            // skip non existing pages
            if(!page_exists($id)) continue;

            $this->prnt($this->getLang('checking') . ' <b>' . hsc($id) . "</b><br />");
            tpl_flush();
            $this->updatepage($id);
        }

        return true;
    }

    /**
     * Rewrite all links and media items in the given page
     *
     * @param string $currentpage the page to rewrite
     */
    protected function updatepage($currentpage) {
        $currentns = getNS($currentpage);

        $text         = rawWiki($currentpage);
        $crc          = md5($text);
        $instructions = p_get_instructions($text);
        $instructions = array_reverse($instructions);

        foreach($instructions as $instruction) {
            if(
                ($this->type == 'links' && $this->isextern  && $instruction[0] == 'externallink') ||
                ($this->type == 'links' && !$this->isextern && $instruction[0] == 'internallink') ||
                ($this->type == 'media' && $this->isextern  && $instruction[0] == 'externalmedia') ||
                ($this->type == 'media' && !$this->isextern && $instruction[0] == 'internalmedia')
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
                if($this->isextern) {
                    $full = mb_strtolower($full);
                } else {
                    if($this->type == 'links') {
                        resolve_pageid($currentns, $full, $exists);
                    } else {
                        resolve_mediaid($currentns, $full, $exists);
                    }
                }

                // create the new link
                $newlink = $this->changeLink($link, $full, $currentns);

                // replace the link
                if(($link != $newlink) && ($full != cleanID($newlink))) {
                    $this->prnt('&nbsp;&nbsp;&nbsp;' . hsc($link) . ' → ' . hsc($newlink) . '<br />');

                    $text = substr($text, 0, $pos) .
                        $newlink . // new link
                        substr($text, $pos + strlen($link)); // continue after old link
                }

            }
            // everything else is ignored
        }
        if($crc == md5($text)) {
            $this->prnt('✗ '.$this->getLang('fail').'<br />');
        } else {
            if($this->dryrun) {
                $this->prnt('✓ '.$this->getLang('successdry').'<br />');
            } else {
                saveWikiText($currentpage, $text, $this->getLang('summary'), true);
                $this->prnt('✓ '.$this->getLang('success').'<br />');
            }
        }
        tpl_flush();
    }

    /**
     * Wrapper around echo, for better testability
     *
     * @param $string
     */
    protected function prnt($string) {
        echo $string;
    }

    /**
     * Rewrite the given link according to the given settings
     *
     * @param string $link      the current link as found in the page source
     * @param string $full      the full, resolved version of the above link
     * @param string $currentNS the namespace of the page the link was found in
     * @return string the corrected link
     */
    protected function changeLink($link, $full, $currentNS) {
        // make sure the prefix matches
        if($this->changefrom && strpos($full, "$this->changefrom") !== 0) return $link;

        // strip prefix
        $new = substr($full, strlen($this->changefrom));

        // add prefix
        $new = $this->changeto . $new;

        if(!$this->isextern) {
            // strip left over colons
            $new = ltrim($new, ':');

            // make absolute if needed
            if($currentNS && strpos($new, ':') === false) $new = ":$new";
        }

        return $new;
    }

}

// vim:ts=4:sw=4:et:
