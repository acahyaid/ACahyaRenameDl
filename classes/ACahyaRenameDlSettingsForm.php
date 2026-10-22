<?php

namespace APP\plugins\generic\ACahyaRenameDl\classes;

use APP\core\Application;
use APP\plugins\generic\ACahyaRenameDl\ACahyaRenameDlPlugin;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class ACahyaRenameDlSettingsForm extends Form
{
    public ACahyaRenameDlPlugin $plugin;

    public function __construct(ACahyaRenameDlPlugin $plugin)
    {
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
        $this->plugin = $plugin;

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * List of settings managed by this form
     */
    public function getSettingNames(): array
    {
        return [
            'enablePlugin',
            'presetPattern',
            'namingPattern',
            'replaceSpaces',
            'applyToGalleys',
        ];
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData()
    {
        $context = Application::get()->getRequest()->getContext();
        $contextId = $context ? $context->getId() : Application::SITE_CONTEXT_ID;

        foreach ($this->getSettingNames() as $settingName) {
            $this->setData($settingName, $this->plugin->getSetting($contextId, $settingName));
        }

        // Set sensible defaults if not set
        if ($this->getData('enablePlugin') === null) {
            $this->setData('enablePlugin', true);
        }
        if (!$this->getData('presetPattern')) {
            $this->setData('presetPattern', 'classic32');
        }
        if (!$this->getData('namingPattern')) {
            $this->setData('namingPattern', '{$submissionId}-{$genre}-{$fileId}-{$revision}-{$fileStage}-{$date}.{$ext}');
        }
        if (!$this->getData('replaceSpaces')) {
            $this->setData('replaceSpaces', 'hyphen');
        }
        if ($this->getData('applyToGalleys') === null) {
            $this->setData('applyToGalleys', true);
        }
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData()
    {
        $this->readUserVars($this->getSettingNames());
    }

    /**
     * @copydoc Form::fetch()
     */
    public function fetch($request, $template = null, $display = false)
    {
        $context = $request->getContext();
        $templateMgr = TemplateManager::getManager($request);

        $presets = [
            'classic32' => __('plugins.generic.acahyarenamedl.settings.preset.classic32'),
            'idOriginalName' => __('plugins.generic.acahyarenamedl.settings.preset.idOriginalName'),
            'idGenreOriginalName' => __('plugins.generic.acahyarenamedl.settings.preset.idGenreOriginalName'),
            'idAuthorGenreDate' => __('plugins.generic.acahyarenamedl.settings.preset.idAuthorGenreDate'),
            'custom' => __('plugins.generic.acahyarenamedl.settings.preset.custom'),
        ];

        $spaceOptions = [
            'hyphen' => __('plugins.generic.acahyarenamedl.settings.replaceSpaces.hyphen'),
            'underscore' => __('plugins.generic.acahyarenamedl.settings.replaceSpaces.underscore'),
            'keep' => __('plugins.generic.acahyarenamedl.settings.replaceSpaces.keep'),
        ];

        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'contextId' => $context ? $context->getId() : 0,
            'presets' => $presets,
            'spaceOptions' => $spaceOptions,
        ]);

        foreach ($this->getSettingNames() as $settingName) {
            $templateMgr->assign($settingName, $this->getData($settingName));
        }

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $context = Application::get()->getRequest()->getContext();
        $contextId = $context ? $context->getId() : Application::SITE_CONTEXT_ID;

        foreach ($this->getSettingNames() as $settingName) {
            $value = $this->getData($settingName);
            // Handle boolean checkbox conversion
            if ($settingName === 'enablePlugin' || $settingName === 'applyToGalleys') {
                $value = (bool) $value;
            }
            $this->plugin->updateSetting($contextId, $settingName, $value);
        }

        parent::execute(...$functionArgs);
    }
}
