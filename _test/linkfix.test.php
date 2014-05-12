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


    public function test_internal() {
        $plugin = new test_admin_plugin_linkfix();

        $plugin->changefrom = 'linkfix:';
        $plugin->changeto   = 'linkfox:';
        $plugin->execute();

        $text = rawWiki('linkfix:linkfix');
        $this->assertRegExp('/linkfox:linkfix\|case 1/', $text);
    }

    public function test_external() {
        $plugin = new test_admin_plugin_linkfix();

        $plugin->changefrom = 'http://www.google';
        $plugin->changeto   = 'http://www.foogle';
        $plugin->isextern   = true;
        $plugin->execute();

        $text = rawWiki('linkfix:linkfix');
        $this->assertRegExp('/http:\/\/www\.foogle\.com\|case 2/', $text);
    }

    public function test_internalmedia() {
        $plugin = new test_admin_plugin_linkfix();

        $plugin->changefrom = 'something:logo.';
        $plugin->changeto   = 'something:icon.';
        $plugin->type = 'media';
        $plugin->execute();

        $text = rawWiki('linkfix:linkfix');
        $this->assertRegExp('/\{\{ something:icon\.png \|case 3\}\}/', $text);
    }

    public function test_externalmedia() {
        $plugin = new test_admin_plugin_linkfix();

        $plugin->changefrom = 'http://www.google';
        $plugin->changeto   = 'http://www.foogle';
        $plugin->isextern   = true;
        $plugin->type = 'media';
        $plugin->execute();

        $text = rawWiki('linkfix:linkfix');
        $this->assertRegExp('/{{ http:\/\/www\.foogle\.com\/logo.png |case 4}}/', $text);
    }
}

class test_admin_plugin_linkfix extends admin_plugin_linkfix {
    public $searchin = '';
    public $changefrom = '';
    public $changeto = '';
    public $dryrun = false;
    public $type = 'links';
    public $isextern = false;

    public function __construct() {

    }

    public function execute(){
        return parent::execute();
    }

    protected function prnt($string) {

    }
}