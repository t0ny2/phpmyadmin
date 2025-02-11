<?php

declare(strict_types=1);

namespace PhpMyAdmin;

use FilesystemIterator;
use PhpMyAdmin\Html\MySQLDocumentation;
use PhpMyAdmin\Plugins\AuthenticationPlugin;
use PhpMyAdmin\Plugins\ExportPlugin;
use PhpMyAdmin\Plugins\ImportPlugin;
use PhpMyAdmin\Plugins\Plugin;
use PhpMyAdmin\Plugins\SchemaPlugin;
use PhpMyAdmin\Properties\Options\Groups\OptionsPropertySubgroup;
use PhpMyAdmin\Properties\Options\Items\BoolPropertyItem;
use PhpMyAdmin\Properties\Options\Items\DocPropertyItem;
use PhpMyAdmin\Properties\Options\Items\HiddenPropertyItem;
use PhpMyAdmin\Properties\Options\Items\MessageOnlyPropertyItem;
use PhpMyAdmin\Properties\Options\Items\NumberPropertyItem;
use PhpMyAdmin\Properties\Options\Items\RadioPropertyItem;
use PhpMyAdmin\Properties\Options\Items\SelectPropertyItem;
use PhpMyAdmin\Properties\Options\Items\TextPropertyItem;
use PhpMyAdmin\Properties\Options\OptionsPropertyItem;
use PhpMyAdmin\Properties\Plugins\ExportPluginProperties;
use PhpMyAdmin\Properties\Plugins\PluginPropertyItem;
use PhpMyAdmin\Properties\Plugins\SchemaPluginProperties;
use SplFileInfo;
use Throwable;

use function __;
use function array_pop;
use function class_exists;
use function count;
use function explode;
use function get_class;
use function htmlspecialchars;
use function mb_strlen;
use function mb_strpos;
use function mb_strtolower;
use function mb_strtoupper;
use function mb_substr;
use function method_exists;
use function preg_match_all;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strcasecmp;
use function strcmp;
use function strtolower;
use function ucfirst;
use function usort;

class Plugins
{
    /**
     * Instantiates the specified plugin type for a certain format
     *
     * @param string            $type   the type of the plugin (import, export, etc)
     * @param string            $format the format of the plugin (sql, xml, et )
     * @param array|string|null $param  parameter to plugin by which they can decide whether they can work
     * @psalm-param array{export_type: string, single_table: bool}|string|null $param
     *
     * @return object|null new plugin instance
     */
    public static function getPlugin(string $type, string $format, $param = null): ?object
    {
        global $plugin_param;

        $plugin_param = $param;
        $pluginType = mb_strtoupper($type[0]) . mb_strtolower(mb_substr($type, 1));
        $pluginFormat = mb_strtoupper($format[0]) . mb_strtolower(mb_substr($format, 1));
        $class = sprintf('PhpMyAdmin\\Plugins\\%s\\%s%s', $pluginType, $pluginType, $pluginFormat);
        if (! class_exists($class)) {
            return null;
        }

        return new $class();
    }

    /**
     * @param string $type server|database|table|raw
     *
     * @return ExportPlugin[]
     */
    public static function getExport(string $type, bool $singleTable): array
    {
        global $plugin_param;

        $plugin_param = ['export_type' => $type, 'single_table' => $singleTable];

        return self::getPlugins('Export');
    }

    /**
     * @param string $type server|database|table
     *
     * @return ImportPlugin[]
     */
    public static function getImport(string $type): array
    {
        global $plugin_param;

        $plugin_param = $type;

        return self::getPlugins('Import');
    }

    /**
     * @return SchemaPlugin[]
     */
    public static function getSchema(): array
    {
        return self::getPlugins('Schema');
    }

    /**
     * Reads all plugin information
     *
     * @param string $type the type of the plugin (import, export, etc)
     *
     * @return array list of plugin instances
     */
    private static function getPlugins(string $type): array
    {
        try {
            $files = new FilesystemIterator(ROOT_PATH . 'libraries/classes/Plugins/' . $type);
        } catch (Throwable $e) {
            return [];
        }

        $plugins = [];

        /** @var SplFileInfo $fileInfo */
        foreach ($files as $fileInfo) {
            if (! $fileInfo->isReadable() || ! $fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }

            if (! str_starts_with($fileInfo->getFilename(), $type)) {
                continue;
            }

            $class = sprintf('PhpMyAdmin\\Plugins\\%s\\%s', $type, $fileInfo->getBasename('.php'));
            if (! class_exists($class)) {
                continue;
            }

            $plugin = new $class();
            if (! ($plugin instanceof Plugin) || ! $plugin->isAvailable()) {
                continue;
            }

            $plugins[] = $plugin;
        }

        usort($plugins, static function (Plugin $plugin1, Plugin $plugin2): int {
            return strcasecmp($plugin1->getProperties()->getText(), $plugin2->getProperties()->getText());
        });

        return $plugins;
    }

    /**
     * Returns locale string for $name or $name if no locale is found
     *
     * @param string|null $name for local string
     *
     * @return string  locale string for $name
     */
    public static function getString($name)
    {
        return $GLOBALS[$name] ?? $name ?? '';
    }

    /**
     * Returns html input tag option 'checked' if plugin $opt
     * should be set by config or request
     *
     * @param string $section name of config section in
     *                        $GLOBALS['cfg'][$section] for plugin
     * @param string $opt     name of option
     *
     * @return string  html input tag option 'checked'
     */
    public static function checkboxCheck($section, $opt)
    {
        // If the form is being repopulated using $_GET data, that is priority
        if (
            isset($_GET[$opt])
            || ! isset($_GET['repopulate'])
            && ((! empty($GLOBALS['timeout_passed']) && isset($_REQUEST[$opt]))
            || ! empty($GLOBALS['cfg'][$section][$opt]))
        ) {
            return ' checked="checked"';
        }

        return '';
    }

    /**
     * Returns default value for option $opt
     *
     * @param string $section name of config section in
     *                        $GLOBALS['cfg'][$section] for plugin
     * @param string $opt     name of option
     *
     * @return string  default value for option $opt
     */
    public static function getDefault($section, $opt)
    {
        if (isset($_GET[$opt])) {
            // If the form is being repopulated using $_GET data, that is priority
            return htmlspecialchars($_GET[$opt]);
        }

        if (isset($GLOBALS['timeout_passed'], $_REQUEST[$opt]) && $GLOBALS['timeout_passed']) {
            return htmlspecialchars($_REQUEST[$opt]);
        }

        if (! isset($GLOBALS['cfg'][$section][$opt])) {
            return '';
        }

        $matches = [];
        /* Possibly replace localised texts */
        if (
            ! preg_match_all(
                '/(str[A-Z][A-Za-z0-9]*)/',
                (string) $GLOBALS['cfg'][$section][$opt],
                $matches
            )
        ) {
            return htmlspecialchars((string) $GLOBALS['cfg'][$section][$opt]);
        }

        $val = $GLOBALS['cfg'][$section][$opt];
        foreach ($matches[0] as $match) {
            if (! isset($GLOBALS[$match])) {
                continue;
            }

            $val = str_replace($match, $GLOBALS[$match], $val);
        }

        return htmlspecialchars($val);
    }

    /**
     * Returns html select form element for plugin choice
     * and hidden fields denoting whether each plugin must be exported as a file
     *
     * @param string $section name of config section in
     *                        $GLOBALS['cfg'][$section] for plugin
     * @param string $name    name of select element
     * @param array  $list    array with plugin instances
     * @param string $cfgname name of config value, if none same as $name
     *
     * @return array<string, array<string, bool|string>|string>
     * @psalm-return array{
     *   name: string,
     *   options: list<array{name: string, text: string, is_selected: bool, force_file: bool}>
     * }
     */
    public static function getChoice($section, $name, array $list, $cfgname = null): array
    {
        if (! isset($cfgname)) {
            $cfgname = $name;
        }

        $return = ['name' => $name, 'options' => []];
        $default = self::getDefault($section, $cfgname);

        foreach ($list as $plugin) {
            $elem = explode('\\', get_class($plugin));
            $plugin_name = (string) array_pop($elem);
            unset($elem);
            $plugin_name = mb_strtolower(mb_substr($plugin_name, mb_strlen($section)));

            // If the form is being repopulated using $_GET data, that is priority
            $isSelected = isset($_GET[$name]) && $plugin_name === $_GET[$name]
                || ! isset($_GET[$name]) && $plugin_name === $default;

            /** @var PluginPropertyItem $properties */
            $properties = $plugin->getProperties();
            $text = null;
            if ($properties != null) {
                $text = $properties->getText();
            }

            /** @var ExportPluginProperties|SchemaPluginProperties $properties */
            $properties = $plugin->getProperties();

            // Whether each plugin has to be saved as a file
            $forceFile = ! strcmp($section, 'Import') || $properties != null && $properties->getForceFile() != null;

            $return['options'][] = [
                'name' => $plugin_name,
                'text' => self::getString($text),
                'is_selected' => $isSelected,
                'force_file' => $forceFile,
            ];
        }

        return $return;
    }

    /**
     * Returns single option in a list element
     *
     * @param string              $section       name of config section in $GLOBALS['cfg'][$section] for plugin
     * @param string              $plugin_name   unique plugin name
     * @param OptionsPropertyItem $propertyGroup options property main group instance
     * @param bool                $is_subgroup   if this group is a subgroup
     *
     * @return string  table row with option
     */
    public static function getOneOption(
        $section,
        $plugin_name,
        &$propertyGroup,
        $is_subgroup = false
    ) {
        $ret = "\n";

        $properties = null;
        if (! $is_subgroup) {
            // for subgroup headers
            if (mb_strpos(get_class($propertyGroup), 'PropertyItem')) {
                $properties = [$propertyGroup];
            } else {
                // for main groups
                $ret .= '<div class="export_sub_options" id="' . $plugin_name . '_'
                    . $propertyGroup->getName() . '">';

                $text = null;
                if (method_exists($propertyGroup, 'getText')) {
                    $text = $propertyGroup->getText();
                }

                if ($text != null) {
                    $ret .= '<h4>' . self::getString($text) . '</h4>';
                }

                $ret .= '<ul>';
            }
        }

        if (! isset($properties)) {
            $not_subgroup_header = true;
            if (method_exists($propertyGroup, 'getProperties')) {
                $properties = $propertyGroup->getProperties();
            }
        }

        $property_class = null;
        if (isset($properties)) {
            /** @var OptionsPropertySubgroup $propertyItem */
            foreach ($properties as $propertyItem) {
                $property_class = get_class($propertyItem);
                // if the property is a subgroup, we deal with it recursively
                if (mb_strpos($property_class, 'Subgroup')) {
                    // for subgroups
                    // each subgroup can have a header, which may also be a form element
                    /** @var OptionsPropertyItem|null $subgroup_header */
                    $subgroup_header = $propertyItem->getSubgroupHeader();
                    if ($subgroup_header !== null) {
                        $ret .= self::getOneOption(
                            $section,
                            $plugin_name,
                            $subgroup_header
                        );
                    }

                    $ret .= '<li class="subgroup"><ul';
                    if ($subgroup_header !== null) {
                        $ret .= ' id="ul_' . $subgroup_header->getName() . '">';
                    } else {
                        $ret .= '>';
                    }

                    $ret .=  self::getOneOption(
                        $section,
                        $plugin_name,
                        $propertyItem,
                        true
                    );
                    continue;
                }

                // single property item
                $ret .= self::getHtmlForProperty(
                    $section,
                    $plugin_name,
                    $propertyItem
                );
            }
        }

        if ($is_subgroup) {
            // end subgroup
            $ret .= '</ul></li>';
        } else {
            // end main group
            if (! empty($not_subgroup_header)) {
                $ret .= '</ul></div>';
            }
        }

        if (method_exists($propertyGroup, 'getDoc')) {
            $doc = $propertyGroup->getDoc();
            if ($doc != null) {
                if (count($doc) === 3) {
                    $ret .= MySQLDocumentation::show(
                        $doc[1],
                        false,
                        null,
                        null,
                        $doc[2]
                    );
                } elseif (count($doc) === 1) {
                    $ret .= MySQLDocumentation::showDocumentation('faq', $doc[0]);
                } else {
                    $ret .= MySQLDocumentation::show(
                        $doc[1]
                    );
                }
            }
        }

        // Close the list element after $doc link is displayed
        if ($property_class !== null) {
            if (
                $property_class == BoolPropertyItem::class
                || $property_class == MessageOnlyPropertyItem::class
                || $property_class == SelectPropertyItem::class
                || $property_class == TextPropertyItem::class
            ) {
                $ret .= '</li>';
            }
        }

        return $ret . "\n";
    }

    /**
     * Get HTML for properties items
     *
     * @param string              $section      name of config section in
     *                                          $GLOBALS['cfg'][$section] for plugin
     * @param string              $plugin_name  unique plugin name
     * @param OptionsPropertyItem $propertyItem Property item
     *
     * @return string
     */
    public static function getHtmlForProperty(
        $section,
        $plugin_name,
        $propertyItem
    ) {
        $ret = '';
        $property_class = get_class($propertyItem);
        switch ($property_class) {
            case BoolPropertyItem::class:
                $ret .= '<li>' . "\n";
                $ret .= '<input type="checkbox" name="' . $plugin_name . '_'
                . $propertyItem->getName() . '"'
                . ' value="something" id="checkbox_' . $plugin_name . '_'
                . $propertyItem->getName() . '"'
                . ' '
                . self::checkboxCheck(
                    $section,
                    $plugin_name . '_' . $propertyItem->getName()
                );

                if ($propertyItem->getForce() != null) {
                    // Same code is also few lines lower, update both if needed
                    $ret .= ' onclick="if (!this.checked &amp;&amp; '
                        . '(!document.getElementById(\'checkbox_' . $plugin_name
                        . '_' . $propertyItem->getForce() . '\') '
                        . '|| !document.getElementById(\'checkbox_'
                        . $plugin_name . '_' . $propertyItem->getForce()
                        . '\').checked)) '
                        . 'return false; else return true;"';
                }

                $ret .= '>';
                $ret .= '<label for="checkbox_' . $plugin_name . '_'
                . $propertyItem->getName() . '">'
                . self::getString($propertyItem->getText()) . '</label>';
                break;
            case DocPropertyItem::class:
                echo DocPropertyItem::class;
                break;
            case HiddenPropertyItem::class:
                $ret .= '<li><input type="hidden" name="' . $plugin_name . '_'
                . $propertyItem->getName() . '"'
                . ' value="' . self::getDefault(
                    $section,
                    $plugin_name . '_' . $propertyItem->getName()
                )
                    . '"></li>';
                break;
            case MessageOnlyPropertyItem::class:
                $ret .= '<li>' . "\n";
                $ret .= '<p>' . self::getString($propertyItem->getText()) . '</p>';
                break;
            case RadioPropertyItem::class:
                /**
                 * @var RadioPropertyItem $pitem
                 */
                $pitem = $propertyItem;

                $default = self::getDefault(
                    $section,
                    $plugin_name . '_' . $pitem->getName()
                );

                foreach ($pitem->getValues() as $key => $val) {
                    $ret .= '<li><input type="radio" name="' . $plugin_name
                        . '_' . $pitem->getName() . '" value="' . $key
                        . '" id="radio_' . $plugin_name . '_'
                        . $pitem->getName() . '_' . $key . '"';
                    if ($key == $default) {
                        $ret .= ' checked="checked"';
                    }

                    $ret .= '><label for="radio_' . $plugin_name . '_'
                    . $pitem->getName() . '_' . $key . '">'
                    . self::getString($val) . '</label></li>';
                }

                break;
            case SelectPropertyItem::class:
                /**
                 * @var SelectPropertyItem $pitem
                 */
                $pitem = $propertyItem;
                $ret .= '<li>' . "\n";
                $ret .= '<label for="select_' . $plugin_name . '_'
                . $pitem->getName() . '" class="desc">'
                . self::getString($pitem->getText()) . '</label>';
                $ret .= '<select name="' . $plugin_name . '_'
                . $pitem->getName() . '"'
                . ' id="select_' . $plugin_name . '_'
                . $pitem->getName() . '">';
                $default = self::getDefault(
                    $section,
                    $plugin_name . '_' . $pitem->getName()
                );
                foreach ($pitem->getValues() as $key => $val) {
                    $ret .= '<option value="' . $key . '"';
                    if ($key == $default) {
                        $ret .= ' selected="selected"';
                    }

                    $ret .= '>' . self::getString($val) . '</option>';
                }

                $ret .= '</select>';
                break;
            case TextPropertyItem::class:
                /**
                 * @var TextPropertyItem $pitem
                 */
                $pitem = $propertyItem;
                $ret .= '<li>' . "\n";
                $ret .= '<label for="text_' . $plugin_name . '_'
                . $pitem->getName() . '" class="desc">'
                . self::getString($pitem->getText()) . '</label>';
                $ret .= '<input type="text" name="' . $plugin_name . '_'
                . $pitem->getName() . '"'
                . ' value="' . self::getDefault(
                    $section,
                    $plugin_name . '_' . $pitem->getName()
                ) . '"'
                    . ' id="text_' . $plugin_name . '_'
                    . $pitem->getName() . '"'
                    . ($pitem->getSize() != null
                    ? ' size="' . $pitem->getSize() . '"'
                    : '')
                    . ($pitem->getLen() != null
                    ? ' maxlength="' . $pitem->getLen() . '"'
                    : '')
                    . '>';
                break;
            case NumberPropertyItem::class:
                $ret .= '<li>' . "\n";
                $ret .= '<label for="number_' . $plugin_name . '_'
                    . $propertyItem->getName() . '" class="desc">'
                    . self::getString($propertyItem->getText()) . '</label>';
                $ret .= '<input type="number" name="' . $plugin_name . '_'
                    . $propertyItem->getName() . '"'
                    . ' value="' . self::getDefault(
                        $section,
                        $plugin_name . '_' . $propertyItem->getName()
                    ) . '"'
                    . ' id="number_' . $plugin_name . '_'
                    . $propertyItem->getName() . '"'
                    . ' min="0"'
                    . '>';
                break;
            default:
                break;
        }

        return $ret;
    }

    /**
     * Returns html div with editable options for plugin
     *
     * @param string         $section name of config section in $GLOBALS['cfg'][$section]
     * @param ExportPlugin[] $list    array with plugin instances
     *
     * @return string  html fieldset with plugin options
     */
    public static function getOptions($section, array $list)
    {
        $ret = '';
        // Options for plugins that support them
        foreach ($list as $plugin) {
            $properties = $plugin->getProperties();
            $text = null;
            $options = null;
            if ($properties != null) {
                $text = $properties->getText();
                $options = $properties->getOptions();
            }

            $elem = explode('\\', get_class($plugin));
            $plugin_name = (string) array_pop($elem);
            unset($elem);
            $plugin_name = mb_strtolower(
                mb_substr(
                    $plugin_name,
                    mb_strlen($section)
                )
            );

            $ret .= '<div id="' . $plugin_name
                . '_options" class="format_specific_options">';
            $ret .= '<h3>' . self::getString($text) . '</h3>';

            $no_options = true;
            if ($options !== null && count($options) > 0) {
                foreach ($options->getProperties() as $propertyMainGroup) {
                    // check for hidden properties
                    $no_options = true;
                    foreach ($propertyMainGroup->getProperties() as $propertyItem) {
                        if (strcmp(HiddenPropertyItem::class, get_class($propertyItem))) {
                            $no_options = false;
                            break;
                        }
                    }

                    $ret .= self::getOneOption(
                        $section,
                        $plugin_name,
                        $propertyMainGroup
                    );
                }
            }

            if ($no_options) {
                $ret .= '<p>' . __('This format has no options') . '</p>';
            }

            $ret .= '</div>';
        }

        return $ret;
    }

    public static function getAuthPlugin(): AuthenticationPlugin
    {
        global $cfg;

        /** @psalm-var class-string $class */
        $class = 'PhpMyAdmin\\Plugins\\Auth\\Authentication' . ucfirst(strtolower($cfg['Server']['auth_type']));

        if (! class_exists($class)) {
            Core::fatalError(
                __('Invalid authentication method set in configuration:')
                    . ' ' . $cfg['Server']['auth_type']
            );
        }

        /** @var AuthenticationPlugin $plugin */
        $plugin = new $class();

        return $plugin;
    }
}
