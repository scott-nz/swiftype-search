<?php

namespace Marcz\Swiftype\Extensions;

use Extension;
use Marcz\Swiftype\SwiftypeClient;
use FAQPage;

class Exporter extends Extension
{
    public function updateExport(&$data, &$clientClassName)
    {
        if ($clientClassName === SwiftypeClient::class) {
            $faqPage = FAQPage::get()->first();
            $data['fields'][] = [
                'type' => 'text',
                'name' => 'Link',
                'value' => $faqPage->Link('view') . '/' . $data['external_id']
            ];
        }
    }
}
