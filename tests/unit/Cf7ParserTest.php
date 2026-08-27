<?php

namespace justinholtweb\passer\tests\unit;

use justinholtweb\passer\services\FormReader;
use PHPUnit\Framework\TestCase;

/**
 * Contact Form 7's tag syntax.
 *
 * CF7 stores a form as a string of its own markup, so parsing it is the whole of importing it.
 */
class Cf7ParserTest extends TestCase
{
    private FormReader $reader;

    protected function setUp(): void
    {
        $this->reader = new FormReader();
    }

    public function testParsesBasicFields(): void
    {
        $fields = $this->reader->parseCf7Tags('[text your-name][email your-email][textarea your-message]');

        $this->assertCount(3, $fields);
        $this->assertSame('yourName', $fields[0]->handle);
        $this->assertSame('singleLine', $fields[0]->type);
        $this->assertSame('email', $fields[1]->type);
        $this->assertSame('multiLine', $fields[2]->type);
    }

    public function testReadsTheRequiredAsterisk(): void
    {
        $fields = $this->reader->parseCf7Tags('[text* required-name][text optional-name]');

        $this->assertTrue($fields[0]->required);
        $this->assertFalse($fields[1]->required);
    }

    public function testIgnoresTheSubmitTag(): void
    {
        $fields = $this->reader->parseCf7Tags('[text name][submit "Send it"]');

        $this->assertCount(1, $fields);
        $this->assertSame('name', $fields[0]->handle);
    }

    public function testReadsChoiceOptions(): void
    {
        $fields = $this->reader->parseCf7Tags('[select topic "Sales" "Support" "Other"]');

        $this->assertSame('dropdown', $fields[0]->type);
        $this->assertCount(3, $fields[0]->options);
        $this->assertSame('Sales', $fields[0]->options[0]['label']);
        $this->assertSame('Other', $fields[0]->options[2]['value']);
    }

    public function testReadsCheckboxOptions(): void
    {
        $fields = $this->reader->parseCf7Tags('[checkbox interests "Design" "Development"]');

        $this->assertSame('checkboxes', $fields[0]->type);
        $this->assertCount(2, $fields[0]->options);
    }

    /**
     * A quoted string means a placeholder when the `placeholder` option is present, and a
     * default value otherwise. Getting this backwards puts example text in every submission.
     */
    public function testDistinguishesPlaceholderFromDefault(): void
    {
        $withPlaceholder = $this->reader->parseCf7Tags('[text name placeholder "Jane Smith"]');
        $withDefault = $this->reader->parseCf7Tags('[text country "United Kingdom"]');

        $this->assertSame('Jane Smith', $withPlaceholder[0]->placeholder);
        $this->assertNull($withPlaceholder[0]->defaultValue);

        $this->assertSame('United Kingdom', $withDefault[0]->defaultValue);
        $this->assertNull($withDefault[0]->placeholder);
    }

    public function testReadsIdAndClassOptions(): void
    {
        $fields = $this->reader->parseCf7Tags('[text name id:my-field class:wide]');

        $this->assertSame('my-field', $fields[0]->settings['id']);
        $this->assertSame('wide', $fields[0]->settings['class']);
    }

    public function testReadsFileTypes(): void
    {
        $fields = $this->reader->parseCf7Tags('[file cv filetypes:pdf|doc|docx]');

        $this->assertSame('file', $fields[0]->type);
        $this->assertSame(['pdf', 'doc', 'docx'], $fields[0]->settings['allowedExtensions']);
    }

    public function testMapsAcceptanceToAgree(): void
    {
        $fields = $this->reader->parseCf7Tags('[acceptance terms]');

        $this->assertSame('agree', $fields[0]->type);
    }

    public function testMapsRecaptcha(): void
    {
        $fields = $this->reader->parseCf7Tags('[recaptcha]');

        // A bare [recaptcha] has no name token, so there is nothing to import — which is right:
        // Formie handles captchas as an integration rather than a field.
        $this->assertCount(0, $fields);
    }

    public function testHandlesTagsWrappedInMarkup(): void
    {
        $body = <<<HTML
<label>Your name (required)
    [text* your-name]</label>

<label>Your email (required)
    [email* your-email]</label>

[submit "Send"]
HTML;

        $fields = $this->reader->parseCf7Tags($body);

        $this->assertCount(2, $fields);
        $this->assertTrue($fields[0]->required);
        $this->assertSame('yourEmail', $fields[1]->handle);
    }

    public function testGeneratesLegibleLabels(): void
    {
        $fields = $this->reader->parseCf7Tags('[text your-company-name]');

        $this->assertSame('Your company name', $fields[0]->label);
        $this->assertSame('yourCompanyName', $fields[0]->handle);
    }

    public function testRecordsTheOriginalTagName(): void
    {
        $fields = $this->reader->parseCf7Tags('[tel phone-number]');

        $this->assertSame('tel', $fields[0]->sourceType);
        $this->assertSame('phone', $fields[0]->type);
    }

    public function testReturnsNothingForAnEmptyForm(): void
    {
        $this->assertSame([], $this->reader->parseCf7Tags(''));
        $this->assertSame([], $this->reader->parseCf7Tags('<p>Just some markup</p>'));
    }
}
