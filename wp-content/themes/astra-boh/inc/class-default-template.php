<?php
namespace Give\Form\Templates;

use Give\Form\Template;

class DefaultTemplate extends Template {
    public $donationFormStyle = 'onpage';
    public $openSuccessPageInIframe = false;
    public function getID(): string    { return 'default'; }
    public function getName(): string  { return 'Default'; }
    public function getImage(): string { return ''; }
    public function getOptionsConfig(): array { return []; }
}
