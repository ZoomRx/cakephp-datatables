<?php
namespace DataTables\View\Helper;

use Cake\View\Helper;
use Cake\View\StringTemplateTrait;

/**
 * DataTables helper
 *
 *
 */
class DataTablesHelper extends Helper
{

    use StringTemplateTrait;

    protected $_defaultConfig = [];

    public function init(array $options = [])
    {
        // if (isset($options['buttons']) && !isset($options['dom'])) {
        //     $options['dom'] = "<'row'<'col-sm-6'l><'col-sm-6'f>><'row'<'col-sm-12'tr>><'row'<'col-sm-5'i><'col-sm-7'p>>B";
        // }
        $this->setConfig($options);

        return $this;
    }

    public function draw($selector)
    {
        $json = json_encode($this->getConfig());
        //Reset config
        $this->_config = [];
        $json = preg_replace('/"callback:(.*?)"/', '$1', $json);
        $js = "(function () { \n var cakeDataTableOptions = $json;\n";
        $js .= "return initDataTable('$selector', cakeDataTableOptions);\n }).call(null)";
        return $js;
    }
}

