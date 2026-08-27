<?php

namespace justinholtweb\passer\models\wp;

use craft\base\Model;

/**
 * A form, normalised out of Contact Form 7, Gravity Forms, WPForms, Ninja Forms or Formidable.
 */
class WpForm extends Model
{
    public string $sourceId = '';

    /** @var string cf7|gravityforms|wpforms|ninjaforms|formidable */
    public string $plugin = '';

    public string $title = '';
    public ?string $description = null;

    /** @var WpFormField[] */
    public array $fields = [];

    /** @var array<string, mixed> Notification/email settings as the source plugin stored them. */
    public array $notifications = [];

    public ?string $submitLabel = null;
    public ?string $successMessage = null;

    /** @var array<string, mixed> Anything the source plugin knows that has no home above. */
    public array $extra = [];

    /** @var WpFormSubmission[] */
    public array $submissions = [];
}
