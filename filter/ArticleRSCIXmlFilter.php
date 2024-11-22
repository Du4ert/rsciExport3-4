<?php

/**
 * @file plugins/importexport/rsciexport/filter/ArticleRSCIXmlFilter.php
 * @class ArticleRSCIXmlFilter
 * @ingroup plugins_importexport_rsciexport
 *
 * @brief Class that converts an Issue to a RSCI XML document.
 */
namespace APP\plugins\importexport\rsciexport\filter;

require $_SERVER['DOCUMENT_ROOT'] . '/plugins/importexport/rsciexport/vendor/autoload.php';

use Smalot\PdfParser\Parser; //pdf parsing
use Countries\CountryLibrary; // ai generated iso 3166 to country name

use PKP\filter\PersistableFilter;
use Matriphe\ISO639\ISO639;
use DOMDocument;
use APP\core\Application;
use APP\notification\NotificationManager;
use PKP\db\DAORegistry;
use APP\facades\Repo;
use PKP\submission\PKPSubmission;

use PKP\config\Config;

class ArticleRSCIXmlFilter extends PersistableFilter {

    /**
	 * Constructor
	 * @param $filterGroup FilterGroup
	 */
	function __construct($filterGroup) {
        $this->setDisplayName('RSCIExportFilter');
		parent::__construct($filterGroup);
	}


	//
	// Implement template methods from PersistableFilter
	//
	/**
	 * @copydoc PersistableFilter::getClassName()
	 */


	//
	// Implement template methods from Filter
	//
	/**
	 * @see Filter::process()
	 * @param $issue Issue
	 * @return DOMDocument|false
	 */
	function &process(&$issue)
    {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        $locales = Application::get()->getRequest()->getJournal()->getSupportedLocales();
		$langs = array();

        foreach ($locales as $locale)
        {
            $langs[] = $this->_convertLocaleToISO639($locale);
        }
        if (empty(
                Repo::submission()->getCollector()->filterByContextIds([$issue->getData('journalId')])
                ->filterByIssueIds([$issue->getId()])->filterByStatus([PKPSubmission::STATUS_PUBLISHED])
                ->GetMany()->toArray()
                ))
        {
            return null;
        }
        else {
            $doc->append($this->_createJournalNode($doc, Application::get()->getRequest()->getJournal(), $issue, $langs));
        }
        

        return $doc;
	}


    /**
     * Construct and return journal node and nested nodes.
     * @param $doc DOMDocument
     * @param $journal Journal
     * @param $issue Issue
     * @param $langs string[]
     * @return DOMElement|false
     */
    protected function _createJournalNode($doc, $journal, $issue, $langs)
    {
        $journalNode = $doc->createElement('journal');
        $journalNode->append($doc->createElement('titleid', $this->_exportSettings['journalRSCITitleId']));

        $issn = $journal->getData('printIssn');
        if ($issn != '')
            $journalNode->append($doc->createElement('issn', $issn));

        $essn = $journal->getData('onlineIssn');
        if ($essn != '')
            $journalNode->append($doc->createElement('essn', $essn));


        $primaryLocale = $journal->getPrimaryLocale();
        $journalNode->append($this->_createJournalInfoNode($doc, $journal, $primaryLocale));

        $journalNode->append($this->_createIssueNode($doc, $issue, $langs));

        return $journalNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $journal Journal
     * @param $locale string
     * @return DOMElement|false
     */
    protected function _createJournalInfoNode($doc, $journal, $locale)
    {
        $journalInfoNode = $doc->createElement('journalInfo');
        $journalInfoNode->setAttribute('lang', $this->_convertLocaleToISO639($locale));
        
        $journalInfoNode->append($doc->createElement('title', htmlentities($journal->getName($locale), ENT_XML1)));

        return $journalInfoNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $issue Issue
     * @param $langs string[]
     * @return DOMElement|false
     */
    protected function _createIssueNode($doc, $issue, $langs)
    {
        $issueNode = $doc->createElement('issue');
        $issueNode->append($doc->createElement('volume', $issue->getVolume()));
        $issueNode->append($doc->createElement('number', $issue->getNumber()));
        //$issueNode->append($doc->createElement('altNumber', ''));
        $issueNode->append($doc->createElement('dateUni', $issue->getYear()));
        $issueNode->append($doc->createElement('pages', $this->_getIssuePages($issue)));
        

        $filesNode = $doc->createElement('files');
        $coverFileNode = $doc->createElement('file', htmlentities($issue->getLocalizedCoverImage(), ENT_XML1));
        $coverFileNode->setAttribute('desc', 'cover');
        $filesNode->append($coverFileNode);
        $issueNode->append($filesNode);
        $issueNode->append($this->_createArticlesNode($doc, $issue, $langs));

        return $issueNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $issue Issue
     * @param $langs string[]
     * @return DOMElement|false
     */
    protected function _createArticlesNode($doc, $issue, $langs)
    {
        $articlesNode = $doc->createElement('articles');
        $publishedArticles = Repo::submission()->getCollector() 
            ->filterByContextIds([$issue->getData('journalId')])
            ->filterByIssueIds([$issue->getId()])
            ->filterByStatus([PKPSubmission::STATUS_PUBLISHED])
            ->GetMany()->toArray();
        $publishedArticles = $this->_sortArticlesByPages($publishedArticles);
        $lastSectionId = null;

        foreach ($publishedArticles as $article)
        {
            if ($this->_exportSettings['isExportSections'] && ($lastSectionId !== $article->getSectionId()))
            {
                $articlesNode->append($this->_createSectionNode($doc, $article, $langs)); 
            }

            $articlesNode->append($this->_createArticleNode($doc, $article, $langs));
            $lastSectionId = $article->getSectionId();
        }

        return $articlesNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $article Submission published
     * @param $langs string[]
     * @return DOMElement|false
     */
    protected function _createArticleNode($doc, $article, $langs)
    {
        $articleNode = $doc->createElement('article');
        $articleNode->append($doc->createElement('pages', $article->getPages()));

        $section = Repo::section()->get($article->getSectionId());
        
        $sectionAbbrev = $section->getAbbrev(Application::get()->getRequest()->getJournal()->getPrimaryLocale());

        if ($this->_exportSettings['isExportArtTypeFromSectionAbbrev'] && in_array($sectionAbbrev, $this->_artTypes)) //may not work properly
        {
            $articleNode->append($doc->createElement('artType', $sectionAbbrev));
        }
        else
        {
            $articleNode->append($doc->createElement('artType', 'RAR'));
        }

        $authorsNode = $doc->createElement('authors');

        $authors = Repo::author()->getCollector()
            ->filterByPublicationIds([$article->getCurrentPublication()->getId()])
            ->getMany()->toArray();
        $authorCount = 1;
        foreach ($authors as $author)
        {
            $authorsNode->append($this->_createAuthorNode($doc, $author, $authorCount, $langs));
            $authorCount++;
        }
        $articleNode->append($authorsNode);

        $articleNode->append($this->_createArtTitlesNode($doc, $article, $langs));
        $articleNode->append($this->_createAbstractsNode($doc, $article, $langs));

        $articleNode->append($this->_createTextNode($doc, $article));

        $articleNode->append($this->_createCodesNode($doc, $article));
        $articleNode->append($this->_createKeywordsNode($doc, $article, $langs));
        
        $articleNode->append($this->_createReferencesNode($doc, $article));
        $articleNode->append($this->_createDateNode($doc, $article));
        $articleNode->append($this->_createFundingsNode($doc, $article, $langs));
        $articleNode->append($this->_createFilesNode($doc, $article));

        return $articleNode;
    }

    protected $_artTypes = array ('ABS', 'BRV', 'CNF', 'COR', 'EDI', 'MIS', 'PER', 'RAR', 'REP', 'REV', 'SCO', 'UNK');


    /**
     * @param $doc DOMDocument
     * @param $article Submission published
     * @return DOMElement|false
     */
    protected function _createTextNode($doc, $article)
    {
        $textNode = $doc->createElement('text');
        $textNode->setAttribute('lang', $this->_convertLocaleToISO639($article->getLocale()));
        $parser = new Parser();

        $galleys = Repo::galley()->dao->getByPublicationId($article->getCurrentPublication()->getId());
        $galley = array_shift($galleys);
        if (empty($galley) || empty($galley->getFile()))
            return $textNode;
        
        $articleFilePath = $galley->getFile()->getData('path');

        $text = $parser->parseFile(Config::getVar('files', 'files_dir') . '/' . $articleFilePath)->getText();
        $start = $this->_exportSettings['docStartKey'];
        $end = $this->_exportSettings['docEndKey'];
        $startPos = strpos($text, $start);
        if ($startPos === false || $start === "") {
            return $textNode;
        }

        $endPos = strrpos($text, $end);
        if ($endPos === false || $endPos < $startPos || $end === "") {
            $textNode->append(substr($text, $startPos));
        } else {
            $textNode->append(substr($text, $startPos, $endPos - $startPos));
        }

        return $textNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $author Author
     * @param $authorNumber int Serial number of the author in the article authors list
     * @param $langs string[]
     * @return DOMElement|false
     */
    protected function _createAuthorNode($doc, $author, $authorNumber, $langs)
    {
        
        $authorNode = $doc->createElement('author');
        $authorNode->setAttribute('num', strval($authorNumber));

        if($author->getOrcid() != '')
        {
            $authorCodesNode = $doc->createElement('authorCodes');
            $orcidParts = explode('/', $author->getOrcid());
            $orcid = end($orcidParts);
            $authorCodesNode->append($doc->createElement('orcid', $orcid));
            $authorNode->append($authorCodesNode);
        }
        foreach ($langs as $lang)
        {
            $individInfoNode = $doc->createElement('individInfo');
            
            $individInfoNode->setAttribute('lang', $lang);

            $locale = $this->_convertISO639ToLocale($lang);

            $individInfoNode->append($doc->createElement('surname', $author->getFamilyName($locale)));
            $individInfoNode->append($doc->createElement('initials', ($this->_exportSettings['namesAsIs']) ? $author->getGivenName($locale) : $this->_createInitials($author->getGivenName($locale))));

            $individInfoNode->append($doc->createElement('orgName', $author->getAffiliation($locale)));
            if($author->getEmail() != "null@null.null")
                $individInfoNode->append($doc->createElement('email', $author->getEmail()));
            $individInfoNode->append($doc->createElement('address', htmlentities($this->_getOfficialCountryName($author->getData('country'), $locale), ENT_XML1)));
            

            $authorNode->append($individInfoNode);
        }

        return $authorNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $article Submission published
     * @param $langs string[]
     * @return DOMElement|false
     */
    protected function _createArtTitlesNode($doc, $article, $langs)
    {
        $artTitlesNode = $doc->createElement('artTitles');
        foreach ($langs as $lang)
        {
            $locale = $this->_convertISO639ToLocale($lang);
            $artTitleNode = $doc->createElement('artTitle', htmlentities($article->getLocalizedTitle($locale, false), ENT_XML1));
            $artTitleNode->setAttribute('lang', $lang);
            $artTitlesNode->append($artTitleNode);
        }

        return $artTitlesNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $article Submission published
     * @param $langs string[]
     * @return DOMElement|false
     */
    protected function _createAbstractsNode($doc, $article, $langs)
    {
        $abstractsNode = $doc->createElement('abstracts');

        foreach($langs as $lang)
        {
            $locale = $this->_convertISO639ToLocale($lang);
            $abstractNode = $doc->createElement('abstract', htmlentities(strip_tags($article->getAbstract($locale)), ENT_XML1));
            $abstractNode->setAttribute('lang', $lang);
            $abstractsNode->append($abstractNode);
        }

        return $abstractsNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $article Submission published
     * @return DOMElement|false
     */
    protected function _createCodesNode($doc, $article)
    {
        $codesNode = $doc->createElement('codes');
        $submissionSubjectsDAO = DAORegistry::getDAO('SubmissionSubjectDAO');
        if (!empty($submissionSubjectsDAO->getSubjects($article->getCurrentPublication()->getId())))
            $codesNode->append($doc->createElement('udk', htmlentities(array_shift(array_shift($submissionSubjectsDAO->getSubjects($article->getCurrentPublication()->getId()))), ENT_XML1)));
        $codesNode->append($doc->createElement('doi', htmlentities($article->getStoredPubId('doi'), ENT_XML1)));
        $codesNode->append($doc->createElement('udk', $article->getStoredPubId('udc')));
        $codesNode->append($doc->createElement('edn', $article->getStoredPubId('edn')));
        return $codesNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $article Submission published
     * @param $langs string[]
     * @return DOMElement|false
     */
    protected function _createKeywordsNode($doc, $article, $langs)
    {
        $keywordsNode = $doc->createElement('keywords');
        $submissionKeywordsDAO = DAORegistry::getDAO('SubmissionKeywordDAO');

        foreach ($langs as $lang)
        {
            $kwdGroup = $doc->createElement('kwdGroup');
            $kwdGroup->setAttribute('lang', $lang);
            $locale = $this->_convertISO639ToLocale($lang);
            $keywords = $submissionKeywordsDAO->getKeywords($article->getCurrentPublication()->getId(), array($locale))[$locale];

            foreach ($keywords as $locale=>$keyword)
            {
                $kwdGroup->append($doc->createElement('keyword', htmlentities($keyword, ENT_XML1)));
            }
            $keywordsNode->append($kwdGroup);
        }
        return $keywordsNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $article Submission published
     * @param $langs string[]
     * @return DOMElement|false
     */

    // ! Default funding 
    protected function _createFundingsNodeDefault($doc, $article, $langs)
    {
        $fundingsNode = $doc->createElement('fundings');
        $submissionAgencyDAO = DAORegistry::getDAO('SubmissionAgencyDAO');
        foreach ($langs as $lang)
        {
            $locale = $this->_convertISO639ToLocale($lang);
            $agencies = $submissionAgencyDAO->getAgencies($article->getCurrentPublication()->getId(), array($locale));
            $fundGroup = $doc->createElement('funding');
            if (!empty($agencies))
            {
                $fundGroup->append(array_shift(array_shift($agencies)));
                
            }
            $fundGroup->setAttribute('lang', $lang);
                $fundingsNode->append($fundGroup);
        }
        return $fundingsNode;
    }
    // !default funding//

    // ? custon funding
    protected function _createFundingsNode($doc, $article, $langs)
    {
        $fundingsNode = $doc->createElement('fundings');

        foreach($langs as $lang)
        {
            $locale = $this->_convertISO639ToLocale($lang);
            $funding = $article->getCurrentPublication()->getData('funding', $locale);

            $fundingNode = $doc->createElement('funding', htmlentities(strip_tags($funding), ENT_XML1));
            $fundingNode->setAttribute('lang', $lang);
            $fundingsNode->append($fundingNode);
        }

        return $fundingsNode;
    }
    // ? custom funding//

    /**
     * @param $doc DOMDocument
     * @param $article Submission published
     * @return DOMElement|false
     */
    protected function _createReferencesNode($doc, $article)
    {
        $referencesNode = $doc->createElement('references');
        $citationDAO = DAORegistry::getDAO('CitationDAO');
        $citations = $citationDAO->getByPublicationId($article->getCurrentPublication()->getId())->toAssociativeArray();
        $lang = "ANY";

        foreach ($citations as $citation)
        {
            if ($this->_exportSettings['langCitation'] && $citation->getRawCitation() === "###")
                break;
            $referenceNode = $doc->createElement('reference');
            $refInfoNode = $doc->createElement('refInfo');
            $refInfoNode->setAttribute('lang', $lang);
            $textNode = $doc->createElement('text', htmlentities($citation->getRawCitation(), ENT_XML1));
            $refInfoNode->append($textNode);
            $referenceNode->append($refInfoNode);
            $referencesNode->append($referenceNode);
        }

        return $referencesNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $article Submission published
     * @return DOMElement|false
     */
    protected function _createDateNode($doc, $article)
    {
        $datesNode = $doc->createElement('dates');
        $dateReceivedNode = $doc->createElement('dateReceived', array_shift(explode(" ", $article->getDateSubmitted())));
        $datesNode->append($dateReceivedNode);

        return $datesNode;
    }

    /**
     * @param $doc DOMDocument
     * @param $article Submission published
     * @return DOMElement|false
     */
    protected function _createFilesNode($doc, $article)
    {
        $filesNode = $doc->createElement('files');

        $galleys = Repo::galley()->dao->getByPublicationId($article->getCurrentPublication()->getId());
        $pdfgalley = null;
        $file = null;
        foreach ($galleys as $galley)
        {
            if (strcmp($galley->getGalleyLabel(), "PDF") === 0 || strcmp($galley->getGalleyLabel(), "pdf") === 0 || strcmp($galley->getGalleyLabel(), "fulltext") === 0 || strcmp($galley->getGalleyLabel(), "fulltext (Английский)") === 0){
                $file = $galley->getFile();
                $pdfgalley = $galley;
                break;
            }
        }

        if (empty($pdfgalley)) {
            return "";
        }

        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $resourceURL = $request->url($context->getPath(), 'article', 'view', (empty($article->getCurrentPublication()->getData('urlPath'))) ? $article->getId() : $article->getCurrentPublication()->getData('urlPath'), null, null, true);
        $furlNode = $doc->createElement('furl', $resourceURL);
        $furlNode->setAttribute('location', 'publisher');
        $furlNode->setAttribute('version', 'published');
        $filesNode->append($furlNode);
        if (!empty($file))
        {
            foreach ($file->getData('name') as $filename)
            {
                if ($filename !== "")
                {
                    $fileNode = $doc->createElement('file', $filename);
                    $fileNode->setAttribute('desc', 'fullText');
                    $filesNode->append($fileNode);
                    break;
                }
            }
        }

        return $filesNode;
    }

    /**
     * Converts galley filename to RSCI name format for archiving (example: "13-16.pdf").
     * @param $article Submission published
     * @return string
     * @deprecated in 0.1.0.2
     */
    static public function getArticleFileNameToRSCI($article,  $issue)
    {
        $galleys = Repo::galley()->dao->getByPublicationId($article->getCurrentPublication()->getId());
        $file = null;
        foreach ($galleys as $galley)
        {
            if (strcmp($galley->getGalleyLabel(), "PDF") === 0)
                $file = $galley->getFile();
        }
        if (empty($file))
            return "";

        $filename = $file->getData('name');

        return $filename;
    }

    /**
     * @param $affiliationStr string
     * @return Affiliation
     * @deprecated in version 0.1.0.2
     */
    protected function _parseAffiliation($affiliationStr)
    {
        $affiliationsStr = explode(' / ', $affiliationStr);

        $organizations = array();
        $addresses = array();
        foreach ($affiliationsStr as $affiliation)
        {
            $affiliation = trim($affiliation);
            $address = '';
            $organization = $affiliation;

            $lastComma = mb_strrpos($affiliation, ',');
            if ($lastComma !== false) {
                $next_to_last = mb_strrpos($affiliation, ',', $lastComma - mb_strlen($affiliation) - 1);
                $organization = mb_substr($affiliation, 0, $next_to_last);
                $address = mb_substr($affiliation, $next_to_last);
                $address = ltrim($address, ", ");
            }

            $organizations[] = $organization;

            if (!in_array($address, $addresses))
            {
                $addresses[] = $address;
            }
        }

        $affiliation = new Affiliation();

        foreach ($organizations as $organization)
        {
            $affiliation->Organization .= '; ' .  $organization;
        }
        $affiliation->Organization = ltrim($affiliation->Organization, '; ');

        foreach ($addresses as $address)
        {
            $affiliation->Address .= '; ' . $address;
        }
        $affiliation->Address = ltrim($affiliation->Address, '; ');

        return $affiliation;
    }

    /**
     * @param $givenName string
     * @return string Initials with dots and whitespace between
     */
    protected function _createInitials($givenName)
    {
        $names = explode(' ', $givenName);
        $initialsArray = array();
        foreach ($names as $name)
        {
            $initial = mb_substr($name, 0, 1);
            if (mb_strtoupper($initial) === $initial)
            {
                $initialsArray[] = mb_strtoupper($initial);
            }
        }

        $initials = '';
        foreach ($initialsArray as $initial)
        {
            $initials = $initials . $initial . '. ';
        }
        $initials = rtrim($initials, " ");

        return $initials;
    }
    /**
     * @param $issue Issue
     * @return string Starting and ending pages string (start-end).
     */
    protected function _getIssuePages($issue)
    {
        $publishedArticles = Repo::submission()->getCollector()
            ->filterByContextIds([$issue->getData('journalId')])
            ->filterByIssueIds([$issue->getId()])
            ->filterByStatus([PKPSubmission::STATUS_PUBLISHED])
            ->GetMany();
        
        $startingPages = array();
        $endingPages = array();

        foreach ($publishedArticles as $publishedArticle)
        {
            $startingPages[] = intval($publishedArticle->getCurrentPublication()->getStartingPage());
            $endingPages[] = intval($publishedArticle->getCurrentPublication()->getEndingPage());
        }

        $issueStartingPage = strval(min($startingPages));
        $issueEndingPage = strval(max($endingPages));

        return $issueStartingPage . '-' . $issueEndingPage;
    }

    /**
     * @param $locale string
     * @return string
     */
    protected function _convertLocaleToISO639($locale)
    {
        $lang2chars = substr($locale, 0, 2);
        $iso = $this->_getISO639();
        $language = $iso->languageByCode1($lang2chars);
        $lang3chars = $iso->code3ByLanguage($language);

        if ($lang3chars == '')
        {
            $user = Application::get()->getRequest()->getUser();
            $notificationManager = new NotificationManager();
            $notificationManager->createTrivialNotification($user->getId(),
                NOTIFICATION_TYPE_WARNING,
                array('pluginName' => $this->getDisplayName(),
                    'contents' => "Error in converting UNIX locale (" . $locale . ") to ISO639 language! Empty language string will be used."));
        }

        return strtoupper($lang3chars);
    }

    /**
     * @param $lang3chars string
     * @return string
     */
    protected function _convertISO639ToLocale($lang3chars)
    {
        $lang3chars = strtolower($lang3chars);
        $iso = $this->_getISO639();
        $language = $iso->languageByCode3($lang3chars);
        $lang2chars = $iso->code1ByLanguage($language);
        $locales = Application::get()->getRequest()->getJournal()->getSupportedLocales();

        foreach ($locales as $locale)
        {
            $lang2charsFromLocale = substr($locale, 0, 2);
            if (str_contains($lang2charsFromLocale, $lang2chars))
            {
                return $locale;
            }
        }

        // If can't convert ISO639 to UNIX locale:
        $user = Application::get()->getRequest()->getUser();
        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification($user->getId(),
                                            NOTIFICATION_TYPE_WARNING,
                                                        array('pluginName' => $this->getDisplayName(),
                                                              'contents' => "Error in converting ISO639 language (" . $lang3chars . ") to UNIX locale! Primary locale will be used."));
        return Application::get()->getRequest()->getJournal()->getPrimaryLocale();
    }

    /**
     * @var ISO639
     */
    protected $_iso;

    /**
     * @return ISO639
     */
    protected function _getISO639()
    {
        if (!is_a($this->_iso, $this->getClassName()))
        {
            require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/importexport/rsciexport/php-iso-639/src/ISO639.php'); // TODO: import without $_SERVER. import with Autoload
            $this->_iso = new ISO639();
        }
        return $this->_iso;
    }

    protected $_countries;

    protected $_countryLib;
    protected function _getCountryLib()
    {
        if (!is_a($this->_countryLib, $this->getClassName()))
        {
            require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/importexport/rsciexport/countries/CountryLibrary.php'); // TODO: import without $_SERVER. import with Autoload
            $this->_countryLib = new CountryLibrary();
        }
        return $this->_countryLib;
    }

    protected function _getOfficialCountryName($alpha2Code, $language = 'en') {
        return $this->_getCountryLib()->getOfficialCountryName($alpha2Code, $language);
        // too slow
        //if (!isset($this->_countries[$alpha2Code]))
        //{
        //    $url = "https://restcountries.com/v3.1/alpha/" . $alpha2Code;
        //    $response = file_get_contents($url);
        //    $data = json_decode($response, true);
        //    if (isset($data['status']) && $data['status'] === 404)
        //        return null;
        //    $this->_countries[$alpha2Code] = $data[0];
        //}
        //if ($language == 'en') {
        //    return $this->_countries[$alpha2Code]['name']['official'];
        //} elseif ($language == 'ru') {
        //    return $this->_countries[$alpha2Code]['translations']['rus']['official'];
        //}
        //return null;
    }

    protected $_exportSettings;

    /**
     * @param $exportSettings array
     */
    public function SetExportSettings($exportSettings)
    {
        $this->_exportSettings = $exportSettings;
    }


    /**
     * @param DOMDocument $doc
     * @param $article Submission published
     * @param string[] $langs
     * @return DOMElement|false
     */
    protected function _createSectionNode($doc, $article, $langs) // may not work properly
    {
        $section = Repo::section()->get(($article->getSectionId()));
        $sectionNode = $doc->createElement('section');

        foreach ($langs as $lang)
        {
            $locale = $this->_convertISO639ToLocale($lang);
            $secTitleNode = $doc->createElement('secTitle', $section->getTitle($locale));
            $secTitleNode->setAttribute('lang', $lang);
            $sectionNode->append($secTitleNode);
        }
        return $sectionNode;
    }

    /**
     * @param $articles Submission[] published
     * @return Submission[] published
     */
    protected function _sortArticlesByPages($articles)
    {
        $startingPages = array();

        foreach ($articles as $article)
        {
            $startingPage = intval($article->getStartingPage());
            $startingPages += array($startingPage => $article);
        }

        ksort($startingPages, SORT_NUMERIC);

        return array_values($startingPages);
    }
}

class Affiliation
{
    public $Organization = '';
    public $Address = '';
}


