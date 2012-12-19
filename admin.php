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

    public function getMenuSort() {
        return 3000;
    }

    public function handle() {
    }

    public function html() {
        global $INPUT;


        if($INPUT->str('ns')){
            echo "Processing, do not interrupt<br />";
            tpl_flush();

            ignore_user_abort(true);
            set_time_limit(0);
            $this->updatens($INPUT->str('ns'));


            echo "done<br />";
        }else{

            echo '<p>Don\'t use this unless you have any idea of what this is doing (eg. are a CosmoCode employee)</p>';

            $form = new Doku_Form(array());
            $form->addElement(form_makeTextField('ns'));
            $form->addElement(form_makeButton('submit',''));
            $form->printForm();
        }




    }

    private function updatens($ns) {
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
            str_replace(':','/',$ns)
        );

        foreach($data as $file){
            print hsc($file['id'])."<br />";
            tpl_flush();
            $this->updatepage($file['id'], $ns);
        }
    }

    private function updatepage($id, $ns) {
        $text = rawWiki($id);
        $instructions = p_get_instructions($text);
        $instructions = array_reverse($instructions);

        foreach($instructions as $instruction) {
            if($instruction[0] == 'internallink' || $instruction[0] == 'internalmedia') {
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

                // relative links stay
                if(strpos($link, ':') === false) continue;
                if(substr($link, 0, 1) == '.') continue;

                // prepend $NS
                $newlink = "$ns:" . ltrim($link, ':');

                $text = substr($text, 0, $pos) .
                    $newlink . // new link
                    substr($text, $pos + strlen($link)); // continue after old link

            }
            // everything else is ignored
        }

        //dbg($id);
        //dbg($text);
        saveWikiText($id, $text, 'links rebased', true);
    }

}

// vim:ts=4:sw=4:et:
