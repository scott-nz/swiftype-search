<?php

/**
 * Some default things to set up
 *
 */
class SwiftypeSearchSiteConfig extends DataExtension
{

    /**
     * @var array $db
     */
    private static $db = [
        'FAQAPIKey' => 'Varchar(255)',
        'FAQEngineName' => 'Varchar(255)'
    ];

    /**
     * @var array $has_many
     */
    private static $has_many = [];

    /**
     * Settings and CMS form fields for CMS the admin/settings area
     *
     * @param FieldList $fields
     * @return void
     */
    public function updateCMSFields(FieldList $fields)
    {

        // Swiftype Search Tab
        $fields->addFieldsToTab(
            'Root.SwiftypeSearch',
            array(
                LiteralField::create('',
                    '<h3>FAQ Knowledge Base Settings</h3>'),
                TextField::create('FAQAPIKey', 'FAQ API Key'),
                TextField::create('FAQEngineName', 'FAQ Engine Name')
            )
        );
    }
}
