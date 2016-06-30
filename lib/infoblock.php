<?php
/*
TODO

получить список id пропертей
property code 2 id


*/

namespace TAO;
\CModule::IncludeModule("iblock");

/**
 * Class Infoblock
 * @package TAO
 */
abstract class Infoblock
{

    /**
     * @var array
     */
    static $entityClasses = array();

    /**
     * @var bool
     */
    protected $mnemocode = false;
    /**
     * @var array|bool
     */
    protected $data = false;

    /**
     * @var bool
     */
    protected $processed = false;

    /**
     * @var int
     */
    protected $uniqCounter = 0;
    /**
     * @var
     */
    protected $editAreaId;

    /**
     * @var bool
     */
    protected $entityClassName = false;
    /**
     * @var
     */
    protected $_bundle;

    /**
     * @var array
     */
    static $classes = array();
    /**
     * @var array
     */
    static $code2type = array();

    /**
     * @param $type
     * @param $code
     * @param $class
     */
    public static function processSchema($type, $code, $class)
    {
        self::$code2type[$code] = $type;
        self::$classes[$code] = $class;
        $path = \TAO::getClassFile($class);
        if (\TAO::cache()->fileUpdated($path)) {
            $infoblock = new $class($code);
            //var_dump($infoblock);die;
            $infoblock->process();
        }
    }

    /**
     * @param $code
     * @return string
     */
    public static function getClassName($code)
    {
        if (isset(self::$classes[$code])) {
            return self::$classes[$code];
        }
        return '\\TAO\\CachedInfoblock\\' . $code;
    }

    /**
     * Infoblock constructor.
     * @param $code
     */
    public function __construct($code)
    {
        $this->setMnemocode($code);
        $this->data = $this->loadData();
        if (!$this->data) {
            $this->addNewInfoblock();
        } else {
        }
    }

    /**
     * @return bool|mixed
     */
    public function bundle()
    {
        if (is_null($this->_bundle)) {
            $this->_bundle = false;
            $code = $this->getMnemocode();
            $bundle = \TAO::getOption("infoblock.{$code}.bundle");
            if ($bundle) {
                $this->_bundle = $bundle;
            } else {
                $class = get_class($this);
                if (preg_match('{^(TAO|App)\\\\Bundle\\\\([^\\\\]+)\\\\}', $class, $m)) {
                    $name = $m[2];
                    $this->_bundle = \TAO::bundle($name);
                }
            }
        }
        return $this->_bundle;
    }

    /**
     * @param array $args
     * @return array
     */
    public function getSections($args = array())
    {
        $result = \CIBlockSection::GetTreeList(
            array('IBLOCK_ID' => $this->id(), 'GLOBAL_ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N')
        );
        $rows = array();
        while ($row = $result->GetNext()) {
            $rows[$row['ID']] = $row;
        }
        return $rows;
    }

    /**
     * @param array $args
     * @return int
     */
    public function getCount($args = array())
    {
        list($order, $filter, $groupBy, $nav, $fields) = $this->convertArgs($args);
        $groupBy = array();
        return (int)\CIBlockElement::GetList($order, $filter, $groupBy, $nav, $fields);
    }

    /**
     * @param $row
     */
    protected function generateDetailUrl(&$row)
    {
        if (isset($row['DETAIL_PAGE_URL'])) {
            $row['DETAIL_PAGE_URL'] = \CIBlock::ReplaceDetailUrl($row['DETAIL_PAGE_URL'], $row, true, 'E');
        }
    }

    /**
     * @param array $args
     * @return array
     */
    public function getRows($args = array())
    {
        list($order, $filter, $groupBy, $nav, $fields) = $this->convertArgs($args);
        $out = array();
        $result = \CIBlockElement::GetList($order, $filter, $groupBy, $nav, $fields);
        while ($row = $result->GetNext(true, false)) {
            $out[] = $row;
        }

        if (is_array($result->arResultAdd)) {
            foreach ($result->arResultAdd as $row) {
                $this->generateDetailUrl($row);
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array $args
     * @return array
     */
    public function getItems($args = array())
    {
        $args['fields'] = array();
        $rows = $this->getRows($args);
        $items = array();
        foreach ($rows as $row) {
            $properties = array();
            $res = \CIBlockElement::GetProperty($row['IBLOCK_ID'], $row['ID']);
            while ($irow = $res->Fetch()) {
                $pid = $irow['ID'];
                $vid = $irow['PROPERTY_VALUE_ID'];
                if (!isset($properties[$pid])) {
                    $properties[$pid] = array();
                }
                $properties[$pid][$vid] = $irow;
            }
            $items[] = $this->makeItem($row, $properties);
        }
        return $items;
    }

    /**
     * @param array $args
     * @return array
     */
    public function getItemsForSelect($args = array())
    {
        $out = array();
        foreach ($this->getItems($args) as $item) {
            $out[$item->id()] = $item->title();
        }
        return $out;
    }

    /**
     * @param $id
     * @param bool|true $checkPermissions
     * @return mixed|void
     */
    public function loadItem($id, $checkPermissions = true, $by = false)
    {
        $param = is_string($by) ? $by : (is_numeric($id) ? 'ID' : 'CODE');

        $items = $this->getItems(array(
            'filter' => array($param => $id),
            'check_permissions' => $checkPermissions,
        ));

        if (count($items) == 0) {
            return;
        }

        return array_shift($items);
    }

    /**
     * @param $id
     * @param bool|true $checkPermissions
     * @return bool
     */
    public function deleteItem($id, $checkPermissions = true)
    {
        global $DB;
        if ($checkPermissions) {
            $item = $this->loadItem($id);
            if (!$item) {
                return false;
            }
            if (!$this->accessDelete($item)) {
                return false;
            }
        }
        $DB->StartTransaction();

        if (!\CIBlockElement::Delete($id)) {
            $DB->Rollback();
            return false;
        } else {
            $DB->Commit();
        }
        return true;
    }

    /**
     * @return mixed
     */
    public function getPermission()
    {
        return \CIBlock::GetPermission($this->getId());
    }

    /**
     * @param null $item
     * @return bool
     */
    public function accessUpdate($item = null)
    {
        return $this->getPermission() >= 'W';
    }

    /**
     * @param null $item
     * @return bool
     */
    public function accessDelete($item = null)
    {
        return $this->accessUpdate($item);
    }

    /**
     * @return bool
     */
    public function accessInsert()
    {
        return $this->getPermission() >= 'W';
    }

    /**
     * @param null $item
     * @return bool
     */
    public function accessRead($item = null)
    {
        return $this->getPermission() >= 'R';
    }

    /**
     * @return string
     */
    public function description()
    {
        return trim($this->data['DESCRIPTION']);
    }

    /**
     * @param array $row
     * @param array $properties
     * @return mixed
     */
    public function makeItem($row = array(), $properties = array())
    {
        $className = $this->entityClassName();
        $entity = new $className($row, $properties);
        $entity->setInfoblock($this);
        return $entity;
    }

    /**
     * @param $sub
     * @return array
     */
    public function dirs($sub)
    {
        $dirs = array();
        $bundle = $this->bundle();
        $code = $this->getMnemocode();
        if ($bundle) {
            $dirs[] = $bundle->localPath("{$sub}/{$code}");
            $dirs[] = $bundle->taoPath("{$sub}/{$code}");
            $dirs[] = $bundle->localPath($sub);
            $dirs[] = $bundle->taoPath($sub);
        }
        $dirs[] = \TAO::localDir("{$sub}/{$code}");
        $dirs[] = \TAO::localDir($sub);
        $dirs[] = \TAO::taoDir($sub);
        return $dirs;
    }

    /**
     * @param $file
     * @return mixed
     */
    public function viewPath($file, $extra = false)
    {

        $dirs = $this->dirs('views');
        $path = \TAO::filePath($dirs, $file, $extra);
        return $path;
    }

    /**
     * @param $file
     * @return mixed|string
     */
    public function styleUrl($file, $extra = false)
    {
        $dirs = $this->dirs('styles');
        $url = \TAO::fileUrl($dirs, $file, $extra);
        return $url;
    }

    /**
     * @param $file
     * @return mixed|string
     */
    public function scriptUrl($file, $extra = false)
    {
        $dirs = $this->dirs('scripts');
        $url = \TAO::fileUrl($dirs, $file, $extra);
        return $url;
    }

    /**
     * @param array $args
     * @return string
     */
    public function render($args = array())
    {
        $code = $this->getMnemocode();
        $itemMode = isset($args['item_mode']) ? $args['item_mode'] : 'teaser';
        $listMode = isset($args['list_mode']) ? $args['list_mode'] : 'default';
        $listClass = isset($args['list_class']) ? $args['list_class'] : "tao-list-{$code}";

        $pagerTop = isset($args['pager_top']) ? $args['pager_top'] : false;
        $pagerBottom = isset($args['pager_bottom']) ? $args['pager_bottom'] : false;

        $count = $this->getCount($args);
        $items = $this->getItems($args);

        if ($count == 0) {
            $path = $this->viewPath("list-empty.phtml", $listMode);
        } else {
            $path = $this->viewPath("list.phtml", $listMode);
        }

        list($order, $filter, $groupBy, $nav, $fields, $other) = $this->convertArgs($args);

        if (isset($other['page']) && isset($other['per_page'])) {
            $pagerVar = $other['pager_var'];
            $page = (int)$other['page'];
            $perPage = (int)$other['per_page'];
            $numPages = ceil($count / $perPage);
            if ($numPages < 2) {
                $pagerTop = false;
                $pagerBottom = false;
            }
        }

        ob_start();
        include($path);
        $content = ob_get_clean();
        return $content;
    }

    /**
     * @param $page
     * @param $numPages
     * @param string $pagerVar
     * @param string $type
     * @return string
     */
    public function renderPageNavigator($page, $numPages, $pagerVar = 'page', $type = 'common')
    {
        return \TAO::pager($pagerVar)->setType($type)->setUrl($_SERVER['REQUEST_URI'])->render($page, $numPages);
    }

    /**
     * @param $code
     * @param $class
     */
    public static function setEntityClass($code, $class)
    {
        self::$entityClasses[$code] = $class;
    }

    /**
     * @return string
     */
    public function entityClassName()
    {
        $code = $this->getMnemocode();
        if (isset(self::$entityClasses[$code])) {
            return self::$entityClasses[$code];
        }
        if (!$this->entityClassName) {
            $path = \TAO::localDir("entity/{$code}.php");
            if (is_file($path)) {
                include_once($path);
                $this->entityClassName = '\\App\\Entity\\' . $code;
            } else {
                if ($bundle = $this->bundle()) {
                    if ($className = $bundle->getEntityClassName($code)) {
                        $this->entityClassName = $className;
                    }
                }
            }
            if (!$this->entityClassName) {
                $this->entityClassName = '\\TAO\\Entity';
            }
        }
        return $this->entityClassName;
    }

    /**
     * @param $args
     * @return array
     */
    protected function convertArgs($args)
    {
        $order = isset($args['order']) ? $args['order'] : array('SORT' => 'ASC', 'NAME' => 'ASC');
        $filter = isset($args['filter']) ? $args['filter'] : array('ACTIVE' => 'Y');
        $groupBy = isset($args['group_by']) ? $args['group_by'] : false;
        $nav = isset($args['nav']) ? $args['nav'] : false;
        $fields = isset($args['fields']) ? $args['fields'] : array();
        $other = array();

        $filter['IBLOCK_ID'] = $this->getId();

        if ((isset($args['page']) || isset($args['pager_var'])) && isset($args['per_page'])) {
            $page = 1;
            $per_page = (int)$args['per_page'];
            $per_page = $per_page > 0 ? $per_page : 1;
            $var = 'page';
            if (isset($args['pager_var'])) {
                $var = $args['pager_var'];
                if (isset($_GET[$var])) {
                    $page = (int)$_GET[$var];
                }
            } else {
                $page = (int)$args['page'];
            }
            $page = $page < 1 ? 1 : $page;
            $nav['iNumPage'] = $page;
            $nav['nPageSize'] = $per_page;
            $other['page'] = $page;
            $other['per_page'] = $per_page;
            $other['pager_var'] = $var;
        }

        if (isset($args['limit'])) {
            $limit = (int)$args['limit'];
            if ($limit > 0) {
                $offset = (int)$args['offset'];
                $nav['iNumPage'] = $offset + 1;
                $nav['nPageSize'] = 1;
                $nav['iNavAddRecords'] = $limit - 1;
            }
        }

        if (isset($args['check_permissions'])) {
            $checkPermissions = $args['check_permissions'];
            if (is_bool($checkPermissions)) {
                $checkPermissions = $checkPermissions ? 'Y' : 'N';
            }
            $filter['CHECK_PERMISSIONS'] = $checkPermissions;
        }

        $properties = $this->properties();

        $_filter = array();
        foreach ($filter as $k => $v) {
            if (isset($properties[$k])) {
                $k = "PROPERTY_{$k}";
            }
            $_filter[$k] = $v;
        }

        $_order = array();
        foreach ($order as $k => $v) {
            if (isset($properties[$k])) {
                $k = "PROPERTY_{$k}";
            }
            $_order[$k] = $v;
        }

        return array($_order, $_filter, $groupBy, $nav, $fields, $other);
    }

    /**
     * @param $mnemocode
     * @return $this
     */
    public function setMnemocode($mnemocode)
    {
        $this->mnemocode = $mnemocode;
        return $this;
    }

    /**
     * @return bool
     */
    public function getMnemocode()
    {
        return $this->mnemocode;
    }

    /**
     * @return array|bool
     */
    public function getData($name = false)
    {
        if (!$name) {
            return $this->data;
        }
        return isset($this->data[$name]) ? $this->data[$name] : null;
    }

    /**
     * @return array
     */
    protected function loadData()
    {
        $result = \CIBlock::GetList(array('SORT' => 'ASC'), array('CODE' => $this->getMnemocode(), 'CHECK_PERMISSIONS' => 'N'));
        return $result->Fetch();
    }

    /**
     * @return bool
     */
    public function title()
    {
        return $this->getMnemocode();
    }

    /**
     * @return bool
     */
    public function isActive()
    {
        return true;
    }

    /**
     * @return int
     */
    public function sort()
    {
        return 500;
    }

    /**
     * @return array
     */
    public function sites()
    {
        $by = 'sort';
        $order = 'asc';
        $result = \CSite::GetList($by, $order);
        $out = array();
        foreach ($result->arResult as $site) {
            $out[] = $site['LID'];
        }
        return $out;
    }

    /**
     * @return array
     */
    public function access()
    {
        return array(2 => 'R');
    }

    /**
     * @return array
     */
    protected function data()
    {
        return array();
    }

    /**
     * @return array
     */
    protected function generateData()
    {
        $data = array(
            'CODE' => $this->getMnemocode(),
            'IBLOCK_TYPE_ID' => self::$code2type[$this->getMnemocode()],
            'NAME' => $this->title(),
            'ACTIVE' => $this->isActive() ? 'Y' : 'N',
            'SORT' => $this->sort(),
            'SITE_ID' => $this->sites(),
            'GROUP_ID' => $this->access(),
        );
        return array_merge($data, $this->data());
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        if (is_array($this->data) && isset($this->data['ID'])) {
            return $this->data['ID'];
        }
    }

    /**
     * @return mixed
     */
    public function id()
    {
        return $this->getId();
    }

    /**
     *
     */
    public function addNewInfoblock()
    {
        $data = $this->generateData();
        $o = new \CIBlock;
        $id = $o->Add($data);
        $data['ID'] = $id;
        $this->data = $data;
        $this->process();
    }

    /**
     *
     */
    public function update()
    {
        $id = $this->getId();
        if ($id) {
            $o = new \CIBlock;
            $o->Update($id, $this->data);
        }
    }

    /**
     * @return array
     */
    public function properties()
    {
        return array();
    }

    /**
     * @return array
     */
    public function fields()
    {
        return array();
    }

    /**
     * @return array
     */
    public function messages()
    {
        return array();
    }

    /**
     * @return array
     */
    public function loadProperties()
    {
        static $out = null;
        if (!is_null($out)) {
            return $out;
        }
        $id = $this->getId();
        if (!$id) {
            return array();
        }
        $out = array();
        $result = \CIBlockProperty::GetList(array(), array('IBLOCK_ID' => $id, 'CHECK_PERMISSIONS' => 'N'));
        while ($row = $result->Fetch()) {
            $code = trim($row['CODE']);
            if ($code == '') {
                $code = 'PROP_' . $row['ID'];
            }
            $out[$code] = $row;
        }
        return $out;
    }

    /**
     * @param $name
     * @return mixed
     */
    public function propertyData($name)
    {
        static $properties = null;
        if (is_null($properties)) {
            $properties = $this->loadProperties();
        }
        if (isset($properties[$name])) {
            return $properties[$name];
        }
    }

    /**
     * @return array
     */
    public function propertiesCodes()
    {
        static $ids = null;
        if (!is_null($ids)) {
            return $ids;
        }
        $ids = array();
        foreach ($this->loadProperties() as $name => $data) {
            if (isset($data['ID'])) {
                $ids[$data['ID']] = $name;
            }
        }
        return $ids;
    }

    /**
     * @param $name
     * @return int
     */
    public function propertyId($name)
    {
        $props = $this->loadProperties();
        if (isset($props[$name])) {
            $data = $props[$name];
            if (isset($data['ID'])) {
                return (int)$data['ID'];
            }
        }
    }

    /**
     * @param $id
     * @return mixed
     */
    public function propertyCode($id)
    {
        $key = $id;
        $codes = $this->propertiesCodes();
        if (isset($codes[$key])) {
            return $codes[$key];
        }
    }

    /**
     * @return array
     */
    public function urls()
    {
        return array();
    }

    /**
     * @return array
     */
    protected function urlsProps()
    {
        $out = array();
        $sort = 0;
        foreach ($this->urls() as $code => $data) {
            $sort++;
            $label = 'Адрес страницы';
            $label = isset($data['caption']) ? $data['caption'] : $label;
            $label = isset($data['label']) ? $data['label'] : $label;
            $out["url_{$code}"] = array(
                'NAME' => $label,
                'SORT' => trim($sort),
                'PROPERTY_TYPE' => 'S',
                'IS_REQUIRED' => 'N',
                'DEFAULT_VALUE' => '',
                'COL_COUNT' => '50',
            );
        }
        return $out;
    }

    /**
     *
     */
    public function process()
    {
        if ($this->processed) {
            return;
        }

        $this->processed = true;

        foreach ($this->generateData() as $k => $v) {
            $this->data[$k] = $v;
        }
        $this->update();

        $o = new \CIBlock();
        $mesages = $this->messages();
        $o->SetMessages($this->getId(), $mesages);
        $fields = $this->fields();
        $o->SetFields($this->getId(), $fields);


        $props = $this->loadProperties();
        $newProps = $this->properties();
        foreach ($this->urlsProps() as $key => $data) {
            $newProps[$key] = $data;
        }

        $o = new \CIBlockProperty();
        foreach ($props as $prop => $data) {
            if (!isset($newProps[$prop])) {
                $o->Delete($data['ID']);
            }
        }
        foreach ($newProps as $prop => $data) {
            $data['CODE'] = $prop;
            if ($data['PROPERTY_TYPE'] == 'E' || $data['PROPERTY_TYPE'] == 'G') {
                if (!isset($data['LINK_IBLOCK_ID'])) {
                    if (isset($data['LINK_IBLOCK_CODE'])) {
                        $data['LINK_IBLOCK_ID'] = self::codeToId($data['LINK_IBLOCK_CODE']);
                    }
                }
            }
            if (isset($props[$prop])) {
                $id = $props[$prop]['ID'];
                $o->Update($id, $data);
            } else {
                $data['IBLOCK_ID'] = $this->getId();
                $id = $o->Add($data);
            }
            if ($data['PROPERTY_TYPE'] == 'L' && isset($data['ITEMS']) && is_array($data['ITEMS'])) {
                $items = array();
                $newItems = $data['ITEMS'];
                $res = \CIBlockPropertyEnum::GetList(array(), array('PROPERTY_ID' => $id, 'CHECK_PERMISSIONS' => 'N'));
                while ($row = $res->Fetch()) {
                    $iid = $row['ID'];
                    $eid = $row['EXTERNAL_ID'];
                    if (!isset($newItems[$eid])) {
                        \CIBlockPropertyEnum::Delete($iid);
                    } else {
                        $items[$eid] = $row;
                    }
                }
                $eo = new \CIBlockPropertyEnum();
                foreach ($newItems as $eid => $edata) {
                    if (is_string($edata)) {
                        $edata = array('VALUE' => $edata);
                    }
                    $edata['PROPERTY_ID'] = $id;
                    $edata['EXTERNAL_ID'] = $eid;
                    $edata['XML_ID'] = $eid;
                    if (isset($items[$eid])) {
                        $eo->Update($items[$eid]['ID'], $edata);
                    } else {
                        $eo->Add($edata);
                    }
                }
            }
        }
    }

    /**
     * @param $code
     * @return bool
     */
    public static function codeToId($code)
    {
        $result = \CIBlock::GetList(array('SORT' => 'ASC'), array('CODE' => $code, 'CHECK_PERMISSIONS' => 'N'));
        $row = $result->Fetch();
        return $row ? $row['ID'] : false;
    }

    /**
     * @param $property
     * @return bool
     */
    public function propertyExists($property)
    {
        static $propertyKeys = null;
        if (is_null($propertyKeys)) {
            $propertyKeys = array();
            foreach ($this->properties() as $k => $data) {
                $propertyKeys[$k] = $k;
            }
        }
        return isset($propertyKeys[$property]);
    }

    /**
     * @param string $mode
     * @param array $args
     * @return array
     */
    public function buildMenu($mode = 'full', $args = array())
    {
        $out = array();
        foreach ($this->getItems($args) as $item) {
            $menuItem = $item->buildMenuItem($mode);
            if ($menuItem) {
                $out[] = $menuItem;
            }
        }
        return $out;
    }

    /**
     * @param $item
     * @return array
     */
    public function navigationItem($item)
    {
        $code = $this->getMnemocode();
        return array(
            'id' => "{$code}" . $item->id(),
            'flag' => "{$code}" . $item->id(),
            'url' => $item->url(),
            'title' => $item->title(),
        );
    }

    /**
     * @return array
     */
    public function navigationTree()
    {
        $out = array();
        foreach ($this->getItems() as $item) {
            if ($navItem = $this->navigationItem($item)) {
                $out[] = $navItem;
            }
        }
        return $out;
    }

    /**
     * @return string
     */
    public function getEditAreaId()
    {
        $this->uniqCounter++;
        $code = $this->getMnemocode();

        $this->editAreaId = "bx_tao_iblockj_{$code}_{$this->uniqCounter}";
        $buttons = \CIBlock::GetPanelButtons($this->getId(), 0, 0, array("SECTION_BUTTONS" => false, "SESSID" => false));
        $addUrl = $buttons["edit"]["add_element"]["ACTION_URL"];
        $messages = $this->messages();
        $addTitle = isset($messages['ELEMENT_ADD']) ? $messages['ELEMENT_ADD'] : 'Добавить';

        $addPopup = \TAO::app()->getPopupLink(array('URL' => $addUrl, "PARAMS" => array('width' => 780, 'height' => 500)));

        $btn = array(
            'URL' => "javascript:{$addPopup}",
            'TITLE' => $addTitle,
            'ICON' => 'bx-context-toolbar-add-icon',
        );

        \TAO::app()->SetEditArea($this->editAreaId, array($btn));

        return $this->editAreaId;
    }

    /**
     * @return int
     */
    public function rebuildElementsUrls()
    {
        $c = 0;
        foreach ($this->getItems() as $item) {
            $c++;
            $item->generateUrls();
        }
        return $c;
    }

    /**
     *
     */
    public static function cliRebuildUrls()
    {
        foreach (\TAO::getOptions() as $k => $v) {
            if (preg_match('{^infoblock\.([a-z0-9_]+)\.route_detail}', $k, $m)) {
                $code = $m[1];
                if ($v) {
                    print "Rebuild elements for {$code}...";
                    $c = \TAO::infoblock($code)->rebuildElementsUrls();
                    print "{$c}\n";
                }
            }
        }
    }
}
