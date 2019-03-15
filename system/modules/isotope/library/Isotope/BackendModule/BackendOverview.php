<?php

/*
 * Isotope eCommerce for Contao Open Source CMS
 *
 * @copyright  Copyright (C) 2009 - 2019 terminal42 gmbh & Isotope eCommerce Workgroup
 * @link       https://isotopeecommerce.org
 * @license    https://opensource.org/licenses/lgpl-3.0.html
 */

namespace Isotope\BackendModule;


abstract class BackendOverview extends \BackendModule
{

    /**
     * Template
     * @var string
     */
    protected $strTemplate = 'be_iso_overview';

    /**
     * Isotope modules
     * @var array
     */
    protected $arrModules = array();


    /**
     * Get modules
     * @return array
     */
    abstract protected function getModules();

    /**
     * Check if a user has access to the current module
     * @return boolean
     */
    abstract protected function checkUserAccess($module);


    /**
     * Generate the module
     * @return string
     */
    public function generate()
    {
        $this->arrModules = array();

        // enable collapsing legends
        $session = \Session::getInstance()->get('fieldset_states');
        foreach ($this->getModules() as $k => $arrGroup) {
            list($k, $hide) = explode(':', $k, 2);

            if (isset($session['iso_be_overview_legend'][$k])) {
                $arrGroup['collapse'] = !$session['iso_be_overview_legend'][$k];
            } elseif ('hide' === $hide) {
                $arrGroup['collapse'] = true;
            }

            $this->arrModules[$k] = $arrGroup;
        }

        // Open module
        if (\Input::get('mod') != '') {
            return $this->getModule(\Input::get('mod'));
        } // Table set but module missing, fix the saveNcreate link
        elseif (\Input::get('table') != '') {
            foreach ($this->arrModules as $arrGroup) {
                if (isset($arrGroup['modules'])) {
                    foreach ($arrGroup['modules'] as $strModule => $arrConfig) {
                        if (\is_array($arrConfig['tables'])
                            && \in_array(\Input::get('table'), $arrConfig['tables'], true)
                        ) {
                            \Controller::redirect(\Backend::addToUrl('mod=' . $strModule));
                        }
                    }
                }
            }
        }

        return parent::generate();
    }


    /**
     * Generate the module
     */
    protected function compile()
    {
        if (version_compare(VERSION, '4.0', '<')) {
            $versionClass = 'iso_backend3';
        } elseif (version_compare(VERSION, '4.2', '<')) {
            $versionClass = 'iso_backend42';
        } else {
            $versionClass = 'iso_backend44';
        }


        $this->Template->versionClass = $versionClass;
        $this->Template->modules = $this->arrModules;
    }


    /**
     * Open a module and return it as HTML
     * @param string
     * @return mixed
     */
    protected function getModule($module)
    {
        $arrModule = array();
        $dc = null;

        foreach ($this->arrModules as $arrGroup) {
            if (!empty($arrGroup['modules']) && \array_key_exists($module, $arrGroup['modules'])) {
                $arrModule =& $arrGroup['modules'][$module];
            }
        }

        // Check whether the current user has access to the current module
        if (!$this->checkUserAccess($module)) {
            \System::log('Module "' . $module . '" was not allowed for user "' . $this->User->username . '"', __METHOD__, TL_ERROR);
            \Controller::redirect('contao/main.php?act=error');
        }

        // Redirect the user to the specified page
        if ($arrModule['redirect'] != '') {
            \Controller::redirect($arrModule['redirect']);
        }

        $strTable = \Input::get('table');

        if ($strTable == '' && $arrModule['callback'] == '') {
            \Controller::redirect(\Backend::addToUrl('table=' . $arrModule['tables'][0]));
        }

        // Add module style sheet
        if (isset($arrModule['stylesheet'])) {
            $GLOBALS['TL_CSS'][] = $arrModule['stylesheet'];
        }

        // Add module javascript
        if (isset($arrModule['javascript'])) {
            $GLOBALS['TL_JAVASCRIPT'][] = $arrModule['javascript'];
        }

        // Redirect if the current table does not belong to the current module
        if ($strTable != '') {
            if (!\in_array($strTable, (array) $arrModule['tables'], true)) {
                \System::log('Table "' . $strTable . '" is not allowed in module "' . $module . '"', __METHOD__, TL_ERROR);
                \Controller::redirect('contao/main.php?act=error');
            }

            // Load the language and DCA file
            \System::loadLanguageFile($strTable);
            \Controller::loadDataContainer($strTable);

            // Include all excluded fields which are allowed for the current user
            if ($GLOBALS['TL_DCA'][$strTable]['fields']) {
                foreach ($GLOBALS['TL_DCA'][$strTable]['fields'] as $k => $v) {
                    if ($v['exclude'] && \BackendUser::getInstance()->hasAccess($strTable . '::' . $k, 'alexf')) {
                        $GLOBALS['TL_DCA'][$strTable]['fields'][$k]['exclude'] = false;
                    }
                }
            }

            // Fabricate a new data container object
            if (!\strlen($GLOBALS['TL_DCA'][$strTable]['config']['dataContainer'])) {
                \System::log('Missing data container for table "' . $strTable . '"', __METHOD__, TL_ERROR);
                trigger_error('Could not create a data container object', E_USER_ERROR);
            }

            $dataContainer = 'DC_' . $GLOBALS['TL_DCA'][$strTable]['config']['dataContainer'];
            $dc            = new $dataContainer($strTable);
        }

        // AJAX request
        if ($_POST && \Environment::get('isAjaxRequest')) {
            $this->objAjax->executePostActions($dc);
        }

        // Call module callback
        elseif (class_exists($arrModule['callback'])) {

            /** @var \BackendModule $objCallback */
            $objCallback = new $arrModule['callback']($dc, $arrModule);

            return $objCallback->generate();
        }

        // Custom action (if key is not defined in config.php the default action will be called)
        elseif (\Input::get('key') && isset($arrModule[\Input::get('key')])) {
            $objCallback = new $arrModule[\Input::get('key')][0]();

            return $objCallback->{$arrModule[\Input::get('key')][1]}($dc, $strTable, $arrModule);
        } // Default action
        elseif (\is_object($dc)) {
            $act = (string) \Input::get('act');

            if ('' === $act || 'paste' === $act || 'select' === $act) {
                $act = ($dc instanceof \listable) ? 'showAll' : 'edit';
            }

            switch ($act) {
                case 'delete':
                case 'show':
                case 'showAll':
                case 'undo':
                    if (!$dc instanceof \listable) {
                        \System::log('Data container ' . $strTable . ' is not listable', __METHOD__, TL_ERROR);
                        trigger_error('The current data container is not listable', E_USER_ERROR);
                    }
                    break;

                case 'create':
                case 'cut':
                case 'cutAll':
                case 'copy':
                case 'copyAll':
                case 'move':
                case 'edit':
                    if (!$dc instanceof \editable) {
                        \System::log('Data container ' . $strTable . ' is not editable', __METHOD__, TL_ERROR);
                        trigger_error('The current data container is not editable', E_USER_ERROR);
                    }
                    break;
            }

            return $dc->$act();
        }

        return null;
    }
}
