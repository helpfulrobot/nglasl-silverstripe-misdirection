<?php

/**
 *	Simple and regular expression link redirection definitions.
 *	@author Nathan Glasl <nathan@silverstripe.com.au>
 */

class LinkMapping extends DataObject
{

    /**
     *	Manually define the redirect page relationship when the CMS module is not present.
     */

    private static $db = array(
        'LinkType' => "Enum('Simple, Regular Expression', 'Simple')",
        'MappedLink' => 'Varchar(255)',
        'IncludesHostname' => 'Boolean',
        'Priority' => 'Int',
        'RedirectType' => "Enum('Link, Page', 'Link')",
        'RedirectLink' => 'Varchar(255)',
        'RedirectPageID' => 'Int',
        'ResponseCode' => 'Int',
        'ForwardPOSTRequest' => 'Boolean',
        'HostnameRestriction' => 'Varchar(255)'
    );

    private static $defaults = array(
        'ResponseCode' => 303
    );

    /**
     *	Make sure the link mappings are only ordered by priority and specificity when matching.
     */

    private static $default_sort = 'ID DESC';

    private static $searchable_fields = array(
        'LinkType',
        'MappedLink',
        'Priority',
        'RedirectType'
    );

    private static $summary_fields = array(
        'MappedLink',
        'LinkSummary',
        'Priority',
        'RedirectTypeSummary',
        'RedirectPageTitle',
        'isLive'
    );

    private static $field_labels = array(
        'MappedLink' => 'Mapping',
        'LinkSummary' => 'Redirection',
        'RedirectTypeSummary' => 'Redirect Type',
        'RedirectPageTitle' => 'Redirect Page Title',
        'isLive' => 'Is Live?'
    );

    /**
     *	Make sure previous link mappings take precedence.
     */

    private static $priority = 'ASC';

    /**
     *	Keep track of the initial URL for regular expression pattern replacement.
     *
     *	@parameter <{URL}> string
     */

    private $matchedURL;

    public function setMatchedURL($matchedURL)
    {
        $this->matchedURL = $matchedURL;
    }

    /**
     *	CMS users with appropriate access may view any mappings.
     */

    public function canView($member = null)
    {
        return true;
    }

    /**
     *	CMS administrators may edit any mappings.
     */

    public function canEdit($member = null)
    {
        return Permission::checkMember($member, 'ADMIN');
    }

    /**
     *	CMS administrators may create any mappings.
     */

    public function canCreate($member = null)
    {
        return Permission::checkMember($member, 'ADMIN');
    }

    /**
     *	CMS administrators may delete any mappings.
     */

    public function canDelete($member = null)
    {
        return Permission::checkMember($member, 'ADMIN');
    }

    /**
     *	Print the mapped URL associated with this link mapping.
     *
     *	@return string
     */

    public function getTitle()
    {
        return $this->MappedLink;
    }

    /**
     *	Display CMS link mapping configuration.
     */

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        Requirements::css(MISDIRECTION_PATH . '/css/misdirection.css');

        // Remove any fields that are not required in their default state.

        $fields->removeByName('MappedLink');
        $fields->removeByName('IncludesHostname');
        $fields->removeByName('Priority');
        $fields->removeByName('RedirectType');
        $fields->removeByName('RedirectLink');
        $fields->removeByName('RedirectPageID');
        $fields->removeByName('ResponseCode');
        $fields->removeByName('ForwardPOSTRequest');
        $fields->removeByName('HostnameRestriction');

        // Update any fields that are displayed.

        $fields->dataFieldByName('LinkType')->addExtraClass('link-type')->setTitle('Type');

        // Instantiate the required fields.

        $fields->insertBefore(HeaderField::create(
            'MappedLinkHeader',
            'Mapping',
            3
        ), 'LinkType');

        // Retrieve the mapped link configuration as a single grouping.

        $URL = FieldGroup::create(
            TextField::create(
                'MappedLink',
                ''
            )->setRightTitle('This should <strong>not</strong> include the <strong>HTTP/S</strong> scheme'),
            CheckboxField::create(
                'IncludesHostname',
                'Includes Hostname?'
            )
        )->addExtraClass('mapped-link')->setTitle('URL');
        $fields->addFieldToTab('Root.Main', $URL);

        // Generate the 1 - 10 priority selection.

        $range = array();
        for ($iteration = 1; $iteration <= 10; $iteration++) {
            $range[$iteration] = $iteration;
        }
        $fields->addFieldToTab('Root.Main', DropdownField::create(
            'Priority',
            null,
            $range
        ));

        // Retrieve the redirection configuration as a single grouping.

        $fields->addFieldToTab('Root.Main', HeaderField::create(
            'RedirectionHeader',
            'Redirection',
            3
        ));
        $redirect = FieldGroup::create()->addExtraClass('redirect-link');
        $redirect->push(TextField::create(
            'RedirectLink',
            ''
        )->setRightTitle('This requires the <strong>HTTP/S</strong> scheme for an external URL'));

        // Allow redirect page configuration when the CMS module is present.

        if (ClassInfo::exists('SiteTree')) {

            // Allow redirect type configuration.

            if (!$this->RedirectType) {

                // Initialise the default redirect type.

                $this->RedirectType = 'Link';
            }
            $fields->addFieldToTab('Root.Main', SelectionGroup::create(
                'RedirectType',
                array(
                    'Link//To URL' => $redirect,
                    'Page//To Page' => TreeDropdownField::create(
                        'RedirectPageID',
                        '',
                        'SiteTree'
                    )
                )
            )->addExtraClass('field redirect'));
        } else {
            $redirect->setTitle('To URL');
            $fields->addFieldToTab('Root.Main', $redirect);
        }

        // Use third party validation against an external URL.

        if ($this->canEdit()) {
            Requirements::javascript(MISDIRECTION_PATH . '/javascript/misdirection-link-mapping.js');
            $redirect->push(CheckboxField::create(
                'ValidateExternal',
                'Validate External URL?'
            )->addExtraClass('validate-external'));
        }

        // Retrieve the response code selection.

        $responses = Config::inst()->get('SS_HTTPResponse', 'status_codes');
        $selection = array();
        foreach ($responses as $code => $description) {
            if (($code >= 300) && ($code < 400)) {
                $selection[$code] = "{$code}: {$description}";
            }
        }

        // Retrieve the response code configuration as a single grouping.

        $response = FieldGroup::create(
            DropdownField::create(
                'ResponseCode',
                '',
                $selection
            ),
            CheckboxField::create(
                'ForwardPOSTRequest',
                'Forward POST Request?'
            )
        )->addExtraClass('response')->setTitle('Response Code');
        $fields->addFieldToTab('Root.Main', $response);

        // The optional hostname restriction is now deprecated.

        if ($this->HostnameRestriction) {
            $fields->addFieldToTab('Root.Optional', TextField::create(
                'HostnameRestriction'
            ));
        }

        // Allow extension customisation.

        $this->extend('updateLinkMappingCMSFields', $fields);
        return $fields;
    }

    /**
     *	Confirm that the current link mapping is valid.
     */

    public function validate()
    {
        $result = parent::validate();
        if ($this->ValidateExternal && $this->RedirectLink && $result->valid() && !MisdirectionService::is_external_URL($this->RedirectLink)) {

            // Use third party validation to determine an external URL (https://gist.github.com/dperini/729294 and http://mathiasbynens.be/demo/url-regex).

            $result->error('External URL validation failed!');
        }

        // Allow extension customisation.

        $this->extend('validateLinkMapping', $result);
        return $result;
    }

    /**
     *	Unify any URLs that may have been defined.
     */

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        $this->MappedLink = MisdirectionService::unify_URL($this->MappedLink);
        $this->RedirectLink = MisdirectionService::unify_URL($this->RedirectLink);
        $this->HostnameRestriction = MisdirectionService::unify_URL($this->HostnameRestriction);
    }

    /**
     *	Retrieve the page associated with this link mapping redirection.
     *
     *	@return site tree
     */

    public function getRedirectPage()
    {
        return (ClassInfo::exists('SiteTree') && $this->RedirectPageID) ? SiteTree::get_by_id('SiteTree', $this->RedirectPageID) : null;
    }

    /**
     *	Retrieve the redirection URL.
     *
     *	@return string
     */

    public function getLink()
    {
        if ($this->RedirectType === 'Page') {

            // Determine the home page URL when appropriate.

            if (($page = $this->getRedirectPage()) && ($link = ($page->Link() === Director::baseURL()) ? Controller::join_links(Director::baseURL(), 'home/') : $page->Link())) {

                // This is to support multiple sites, where the absolute page URLs are treated as relative.

                return MisdirectionService::is_external_URL($link) ? ltrim($link, '/') : $link;
            }
        } else {

            // Apply the regular expression pattern replacement.

            if ($link = (($this->LinkType === 'Regular Expression') && $this->matchedURL) ? preg_replace("%{$this->MappedLink}%i", $this->RedirectLink, $this->matchedURL) : $this->RedirectLink) {

                // When appropriate, prepend the base URL to match a page redirection.

                return MisdirectionService::is_external_URL($link) ? $link : Controller::join_links(Director::baseURL(), $link);
            }
        }

        // No redirection URL has been found.

        return null;
    }

    /**
     *	Retrieve the redirection hostname.
     *
     *	@return string
     */

    public function getLinkHost()
    {
        if ($this->RedirectType === 'Page') {

            // Determine the home page URL when appropriate.

            if (($page = $this->getRedirectPage()) && ($link = ($page->Link() === Director::baseURL()) ? Controller::join_links(Director::baseURL(), 'home/') : $page->Link())) {

                // Determine whether a redirection hostname exists.

                return MisdirectionService::is_external_URL($link) ? parse_url($link, PHP_URL_HOST) : null;
            }
        } else {

            // Apply the regular expression pattern replacement.

            if ($link = (($this->LinkType === 'Regular Expression') && $this->matchedURL) ? preg_replace("%{$this->MappedLink}%i", $this->RedirectLink, $this->matchedURL) : $this->RedirectLink) {

                // Determine whether a redirection hostname exists.

                return MisdirectionService::is_external_URL($link) ? parse_url($link, PHP_URL_HOST) : null;
            }
        }

        // No redirection hostname has been found.

        return null;
    }

    /**
     *	Retrieve the redirection URL for display purposes.
     *
     *	@return string
     */

    public function getLinkSummary()
    {
        return ($link = $this->getLink()) ? MisdirectionService::unify_URL($link) : '-';
    }

    /**
     *	Retrieve the redirection type for display purposes.
     *
     *	@return string
     */

    public function getRedirectTypeSummary()
    {
        return $this->RedirectType ? $this->RedirectType : '-';
    }

    /**
     *	Retrieve the page title associated with this link mapping redirection.
     *
     *	@return string
     */

    public function getRedirectPageTitle()
    {
        return (($this->RedirectType === 'Page') && ($page = $this->getRedirectPage())) ? $page->Title : '-';
    }

    /**
     *	Determine if the link mapping is live on the current stage.
     *
     *	@return string
     */

    public function isLive()
    {
        return ($this->RedirectType === 'Page') ? ($this->getRedirectPage() ? 'true' : 'false') : '-';
    }
}
