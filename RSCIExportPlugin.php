<?php

/**
 * @file plugins/importexport/rsciexport/RSCIExportPlugin.php
 * @class RSCIExportPlugin
 * @ingroup plugins_importexport_rsci
 *
 * @brief RSCI XML export plugin.
 */
namespace APP\plugins\importexport\rsciexport;

use APP\core\Application;
use APP\facades\Repo;
use APP\template\TemplateManager;
use PKP\plugins\ImportExportPlugin;
use APP\notification\NotificationManager;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\file\FileManager;
use APP\plugins\importexport\rsciexport\classes\form\RSCIExportSettingsForm;
use PKP\submission\PKPSubmission;
use PKP\config\Config;
use PKP\plugins\Hook;
use PKP\notification\PKPNotification;
use PKP\core\PKPString;
use ZipArchive;

class RSCIExportPlugin extends ImportExportPlugin
{
    /**
     * @copyDoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path);
        $this->addLocaleData();
        return $success;
    }

    /**
     * Display the plugin.
     * @param $args array
     * @param $request PKPRequest
     */
    function display($args, $request)
    {
        parent::display($args, $request);
        $templateMgr = TemplateManager::getManager($request);
        // $journal = $request->getJournal();

        switch (array_shift($args)) {
            case 'index':
            case '':
                $templateMgr->display($this->getTemplateResource('index.tpl'));
                break;
            case 'exportIssue':
                $issueIdsArr = (array) $request->getUserVar('selectedIssues');
                if (count($issueIdsArr) > 1 || count($issueIdsArr) < 1)
                {
                    $user = $request->getUser();
                    $notificationManager = new NotificationManager();
                    $notificationManager->createTrivialNotification($user->getId(), PKPNotification::NOTIFICATION_TYPE_ERROR, array('pluginName' => $this->getDisplayName(), 'contents' => "Choose one issue."));
                    $request->redirectUrl(str_replace("exportIssue", "", $request->getRequestPath()));
                    break;
                }
                else {
                    $issueId = $issueIdsArr[0];
                    $exportXml = $this->exportIssue(
                        $issueId,
                        $request->getContext()
                    );
                    if ($exportXml === null){
                        $user = $request->getUser();
                        $notificationManager = new NotificationManager();
                        $notificationManager->createTrivialNotification(
                            $user->getId(),
                            PKPNotification::NOTIFICATION_TYPE_ERROR,
                            array('pluginName' => $this->getDisplayName(),
                            'contents' => "Choose issue with articles"));
                        $request->redirectUrl(str_replace("exportIssue", "", $request->getRequestPath()));
                        break;
                    }
                    $this->_uploadZip($issueId, $exportXml);
                    break;
                }
            default:
                $dispatcher = $request->getDispatcher();
                $dispatcher->handle404();
        }
    }

    /**
     * @copydoc Plugin::manage()
     */
    function manage($args, $request) {
        $user = $request->getUser();

        
        $settingsForm = new RSCIExportSettingsForm($this, $request->getContext()->getId());
        $notificationManager = new NotificationManager();
        switch ($request->getUserVar('verb')) {
            case 'save':
                $settingsForm->readInputData();
                if ($settingsForm->validate()) {
                    $settingsForm->execute([]);
                    $notificationManager->createTrivialNotification($user->getId(), PKPNotification::NOTIFICATION_TYPE_SUCCESS);
                    return new JSONMessage();
                } else {
                    return new JSONMessage(true, $settingsForm->fetch($request));
                }
            case 'index':
                $settingsForm->initData();
                return new JSONMessage(true, $settingsForm->fetch($request));
        }
        return parent::manage($args, $request);
    }

    /**
     * Get the zip with XML for an issue.
     * @param $issueId int
     * @return string XML contents representing the supplied issue IDs.
     */
    function exportIssue($issueId)
    {
        $issue = Repo::issue()->get($issueId);
        
        $xml = '';
        $filterDao = DAORegistry::getDAO('FilterDAO');
        $rsciExportFilters = $filterDao->getObjectsByGroup('issue=>rsci-xml');
        assert(count($rsciExportFilters) == 1); // Assert only a single serialization filter
        $exportFilter = array_shift($rsciExportFilters);
        $context = Application::get()->getRequest()->getContext();
        $exportSettings = array (
            'isExportArtTypeFromSectionAbbrev' => $this->getSetting($context->getId(),'exportArtTypeFromSectionAbbrev'),
            'isExportSections' => $this->getSetting($context->getId(), 'exportSections'),
            'journalRSCITitleId' => $this->getSetting($context->getId(), 'journalRSCITitleId'),
            'docStartKey' => $this->getSetting($context->getId(), 'docStartKey'),
            'docEndKey' => $this->getSetting($context->getId(), 'docEndKey'),
            'langCitation' => $this->getSetting($context->getId(), 'langCitation'),
            'namesAsIs' => $this->getSetting($context->getId(), 'namesAsIs'),
        );
        $exportFilter->SetExportSettings($exportSettings);

        libxml_use_internal_errors(true);
        $issueXml = $exportFilter->execute($issue, true);
        if ($issueXml !== null)
            $xml = $issueXml->saveXml();
        else {
            $xml = null;
        }

        return $xml;
    }

    /**
     * @param $issueId int
     * @param $xml string XML file content
     */
    protected function _uploadZip($issueId, $xml)
    {
        $fileManager = new FileManager();
        $xmlFileName = $this->getExportPath() . 'Markup_unicode.xml';
        $fileManager->writeFile($xmlFileName, $xml);

        $issue = Repo::issue()->get($issueId);

        $articles = Repo::submission()->getCollector()
            ->filterByContextIds([$issue->getData('journalId')])
            ->filterByIssueIds([$issue->getId()])
            ->filterByStatus([PKPSubmission::STATUS_PUBLISHED])
            ->GetMany()->toArray();

        foreach ($articles as $article)
        {
            $galleys = Repo::galley()->dao->getByPublicationId($article->getCurrentPublication()->getId());
            $galley = array_shift($galleys);
            if (empty($galley) || empty($galley->getFile()))
                continue;
            $fileName = "";
            $articleFilePath = $galley->getFile()->getData('path');
            foreach ($galley->getFile()->getData('name') as $filename)
            {
                if ($filename !== "")
                    $fileName = $filename;
            }
            $fileManager->copyFile(Config::getVar('files', 'files_dir') . '/' . $articleFilePath, $this->getExportPath() . $fileName);
        }

        // ZIP:
        
        $zip = new ZipArchive();
        $zipPath = $this->getExportPath().'issue-'.$issue->getNumber().'-'.$issue->getYear() . '.zip';
        if ($zip->open($zipPath, ZipArchive::CREATE)!==TRUE) {
            exit('Невозможно создать архив ZIP (' . $zipPath . '\n');
        }
        $filesToArchive = scandir($this->getExportPath());
        
        foreach($filesToArchive as $file) {
            if (is_file($this->getExportPath(). $file)) {
                $zip->addFile($this->getExportPath() . $file, basename($file));
            }
        }
        $zip->close();

        // UPLOAD:
        // readfile($zipPath);
        $this->downloadByPath($zipPath, '.zip');
        // $fileManager->deleteByPath($zipPath);
        $fileManager->rmtree($this->getExportPath());
    }

    /**
     * @var string
     */
    protected $_generatedTempPath = '';


    //! Function copied from FileManager class

    public function downloadByPath($filePath, $mediaType = null, $inline = false, $fileName = null)
    {
        $result = null;
        if (Hook::call('FileManager::downloadFile', [&$filePath, &$mediaType, &$inline, &$result, &$fileName])) {
            return $result;
        }
        if (is_readable($filePath)) {
            if ($mediaType === null) {
                // If the media type wasn't specified, try to detect.
                $mediaType = PKPString::mime_content_type($filePath);
                if (empty($mediaType)) {
                    $mediaType = 'application/octet-stream';
                }
            }
            if ($fileName === null) {
                // If the filename wasn't specified, use the server-side.
                $fileName = basename($filePath);
            }

            // Clear output buffer
            ob_clean();
            // Stream the file to the end user.
            header("Content-Type: {$mediaType}");
            header('Content-Length: ' . filesize($filePath));
            header('Accept-Ranges: none');
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"{$fileName}\"");
            header('Cache-Control: private'); // Workarounds for IE weirdness
            header('Pragma: public');
            $this->readFileFromPath($filePath, true);
            $returner = true;
        } else {
            $returner = false;
        }
        Hook::call('FileManager::downloadFileFinished', [&$returner]);
        return $returner;
    }

    public function readFileFromPath($filePath, $output = false)
    {
        if (is_readable($filePath)) {
            $f = fopen($filePath, 'rb');
            if (!$f) {
                return false;
            }
            $data = '';
            while (!feof($f)) {
                $data .= fread($f, 4096);
                if ($output) {
                    echo $data;
                    $data = '';
                }
            }
            fclose($f);

            if ($output) {
                return true;
            }
            return $data;
        }
        return false;
    }
    //! Function copied from FileManager class //

    /**
     * @copydoc ImportExportPlugin::getExportPath()
     */
    function getExportPath()
    {
        if ($this->_generatedTempPath === '')
        {
            $exportPath = parent::getExportPath();
            $journal = Application::get()->getRequest()->getJournal();
            $this->_generatedTempPath =  $exportPath . $this->getPluginSettingsPrefix() . 'Temp-' . date('Ymd-His'). $journal->getId() . '/';
        }
        return $this->_generatedTempPath;
    }

    /**
     * @copydoc ImportExportPlugin::getPluginSettingsPrefix()
     */
    function getPluginSettingsPrefix() {
        return 'rsciexport';
    }

    /**
     * Execute import/export tasks using the command-line interface.
     * @param $scriptName The name of the command-line script (displayed as usage info)
     * @param $args Parameters to the plugin
     */
    function executeCLI($scriptName, &$args)
    {
        // TODO: Implement executeCLI() method.
    }

    /**
     * Display the command-line usage information
     * @param $scriptName string
     */
    function usage($scriptName)
    {
        // TODO: Implement usage() method.
    }

    /**
     * Get the name of this plugin. The name must be unique within
     * its category, and should be suitable for part of a filename
     * (ie short, no spaces, and no dependencies on cases being unique).
     *
     * @return string name of plugin
     */
    function getName(): string
    {
        return "RSCIExportPlugin";
    }

    /**
     * Get the display name for this plugin.
     *
     * @return string
     */
    function getDisplayName(): string
    {
        return __('plugins.importexport.rsciexport.displayName');
    }

    /**
     * Get a description of this plugin.
     *
     * @return string
     */
    function getDescription(): string
    {
        return __('plugins.importexport.rsciexport.description');
    }
}
