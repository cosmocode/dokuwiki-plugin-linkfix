<?php
require_once __DIR__ . '/../admin.php';

/**
 * tests for the linkfix plugin
 *
 * @group plugin_linkfix
 * @group plugins
 */
class linkfix_plugin_linkfix_test extends DokuWikiTest {

    public static function setUpBeforeClass(){
        parent::setUpBeforeClass();
        // copy our own config files to the test directory
        TestUtils::rcopy(dirname(DOKU_TMP_DATA), dirname(__FILE__).'/data');
        idx_addPage('linkfix:linkfix');
    }


    public function test_plugininfo() {
        $plugin = new test_admin_plugin_linkfix();

        $plugin->changefrom = 'linkfix:';
        $plugin->changeto   = 'linkfox:';

        ob_start();
        $plugin->execute();
        ob_end_clean();

        $text = rawWiki('linkfix:linkfix');

        $this->assertRegExp('/linkfox:linkfix|case2/', $text);


    }

}

class test_admin_plugin_linkfix extends admin_plugin_linkfix {
    public $searchin = '';
    public $changefrom = '';
    public $changeto = '';
    public $dryrun = false;
    public $type = 'links';

    public function __construct() {

    }

    public function execute(){
        return parent::execute();
    }

    protected function prnt($string) {

    }
}