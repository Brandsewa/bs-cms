<?php

declare(strict_types=1);

namespace BsCms\Tests\Unit\Plugins;

use BsCms\Database\Connection;
use BsCms\Http\Controllers\FormsController;
use BsCms\Http\Controllers\FormSubmissionsController;
use BsCms\Http\Controllers\MarketplaceController;
use BsCms\Http\Controllers\PublicFormsController;
use BsCms\Plugins\ContactFormPlugin;
use BsCms\Plugins\PluginManager;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;

final class PluginManagerAndFormsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create tables for sqlite in-memory
        $pdo->exec("CREATE TABLE bs_plugins (
            id CHAR(26) PRIMARY KEY,
            handle VARCHAR(64) NOT NULL UNIQUE,
            version VARCHAR(32) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 0,
            config TEXT NULL,
            license_key_enc BLOB NULL,
            installed_at TEXT NOT NULL
        )");

        $pdo->exec("CREATE TABLE bs_form_definitions (
            id CHAR(26) PRIMARY KEY,
            handle VARCHAR(64) NOT NULL UNIQUE,
            title VARCHAR(190) NOT NULL,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            fields TEXT NOT NULL,
            settings TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");

        $pdo->exec("CREATE TABLE bs_form_submissions (
            id CHAR(26) PRIMARY KEY,
            form_id CHAR(26) NOT NULL,
            form_handle VARCHAR(64) NOT NULL,
            data TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'unread',
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(512) NULL,
            created_at TEXT NOT NULL
        )");

        $this->connection = new Connection([
            'pdo' => $pdo,
            'driver' => 'sqlite',
            'prefix' => 'bs_',
        ]);

    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testPluginManagerCatalogAndLifecycle(): void
    {
        $contactPlugin = new ContactFormPlugin($this->connection);
        $manager = new PluginManager($this->connection, $contactPlugin);

        // 1. Initial catalog
        $catalog = $manager->getCatalog();
        $this->assertNotEmpty($catalog);

        $contactItem = null;
        foreach ($catalog as $item) {
            if ($item['handle'] === 'contact-form') {
                $contactItem = $item;
                break;
            }
        }
        $this->assertNotNull($contactItem);
        $this->assertSame('Contact Form & Inquiries', $contactItem['name']);
        $this->assertFalse($contactItem['is_installed']);

        // 2. Install plugin
        $installed = $manager->install('contact-form');
        $this->assertSame('contact-form', $installed['handle']);
        $this->assertTrue($installed['is_enabled']);
        $this->assertTrue($manager->isPluginEnabled('contact-form'));

        // 3. Update config
        $updated = $manager->updateConfig('contact-form', ['default_notify_email' => 'admin@example.com']);
        $this->assertSame('admin@example.com', $updated['config']['default_notify_email']);

        // 5. Toggle disable
        $toggled = $manager->toggle('contact-form', false);
        $this->assertFalse($toggled['is_enabled']);
        $this->assertFalse($manager->isPluginEnabled('contact-form'));

        // 6. Uninstall
        $manager->uninstall('contact-form');
        $this->assertNull($manager->getInstalledPlugin('contact-form'));
    }

    public function testFormBuilderAndSubmissionsWorkflow(): void
    {
        $plugin = new ContactFormPlugin($this->connection);

        // 1. Create a dynamic form
        $created = $plugin->createForm([
            'title' => 'Support Inquiry',
            'handle' => 'support-inquiry',
            'description' => 'Contact customer support',
            'status' => 'published',
            'fields' => [
                ['name' => 'customer_name', 'label' => 'Your Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ['name' => 'message', 'label' => 'How can we help?', 'type' => 'textarea', 'required' => true],
            ],
            'settings' => [
                'submit_label' => 'Send Help Request',
                'success_message' => 'Help is on the way!',
                'honeypot_enabled' => true,
            ],
        ]);

        $this->assertNotEmpty($created['id']);
        $this->assertSame('support-inquiry', $created['handle']);
        $this->assertSame('published', $created['status']);
        $this->assertCount(3, $created['fields']);
        $this->assertSame(0, $created['submissions_count']);

        // 2. Prevent duplicate handle
        $this->expectException(\InvalidArgumentException::class);
        $plugin->createForm(['title' => 'Duplicate', 'handle' => 'support-inquiry']);
    }

    public function testFormPublicSubmissionAndInbox(): void
    {
        $plugin = new ContactFormPlugin($this->connection);

        $form = $plugin->createForm([
            'title' => 'Sales Lead',
            'handle' => 'sales-lead',
            'status' => 'published',
            'fields' => [
                ['name' => 'name', 'label' => 'Full Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Work Email', 'type' => 'email', 'required' => true],
                ['name' => 'message', 'label' => 'Requirement', 'type' => 'textarea', 'required' => false],
            ],
            'settings' => [
                'honeypot_enabled' => true,
            ],
        ]);

        // 1. Submit missing required field -> returns validation errors
        $resInvalid = $plugin->submitForm('sales-lead', ['name' => '', 'email' => 'not-an-email']);
        $this->assertFalse($resInvalid['success']);
        $this->assertArrayHasKey('name', $resInvalid['errors']);
        $this->assertArrayHasKey('email', $resInvalid['errors']);

        // 2. Submit with honeypot triggered -> returns success without persisting
        $resHp = $plugin->submitForm('sales-lead', [
            'name' => 'Spambot',
            'email' => 'bot@spam.com',
            '_hp_check' => 'I am a bot',
        ]);
        $this->assertTrue($resHp['success']);
        $subsAfterHp = $plugin->getSubmissions($form['id']);
        $this->assertCount(0, $subsAfterHp);

        // 3. Valid legitimate submission
        $resValid = $plugin->submitForm('sales-lead', [
            'name' => 'Alice Walker',
            'email' => 'alice@company.com',
            'message' => 'Looking for an enterprise license.',
        ], '192.168.1.100', 'Mozilla/5.0');

        $this->assertTrue($resValid['success']);
        $subId = $resValid['submission_id'];
        $this->assertNotEmpty($subId);

        // 4. Verify in inbox / metrics
        $formWithCount = $plugin->getForm('sales-lead');
        $this->assertNotNull($formWithCount);
        $this->assertSame(1, $formWithCount['submissions_count']);
        $this->assertSame(1, $formWithCount['unread_count']);

        $submissions = $plugin->getSubmissions($form['id']);
        $this->assertCount(1, $submissions);
        $this->assertSame('Alice Walker', $submissions[0]['data']['name']);
        $this->assertSame('alice@company.com', $submissions[0]['data']['email']);
        $this->assertSame('unread', $submissions[0]['status']);

        // 5. Mark as read
        $updated = $plugin->updateSubmissionStatus($subId, 'read');
        $this->assertSame('read', $updated['status']);

        $formAfterRead = $plugin->getForm('sales-lead');
        $this->assertNotNull($formAfterRead);
        $this->assertSame(0, $formAfterRead['unread_count']);

        // 6. Delete submission
        $plugin->deleteSubmission($subId);
        $this->assertCount(0, $plugin->getSubmissions($form['id']));
    }

    public function testPublicFormsControllerGetAndSubmit(): void
    {
        $plugin = new ContactFormPlugin($this->connection);
        $plugin->createForm([
            'title' => 'Feedback Form',
            'handle' => 'feedback',
            'status' => 'published',
            'fields' => [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ['name' => 'message', 'label' => 'Feedback', 'type' => 'textarea', 'required' => true],
            ],
            'settings' => [
                'submit_label' => 'Send Feedback',
                'success_message' => 'Thanks for your feedback!',
            ],
        ]);

        // Plugin gating: the public surface answers 404 until the plugin is
        // installed AND enabled from the marketplace.
        $manager = new PluginManager($this->connection, $plugin);
        $controller = new PublicFormsController($plugin, $manager);
        $lockedRequest = new ServerRequest('GET', '/api/v1/public/forms/feedback');
        $lockedResponse = $controller->get($lockedRequest, ['handle' => 'feedback']);
        $this->assertSame(404, $lockedResponse->getStatusCode());

        $manager->install(ContactFormPlugin::HANDLE);

        // GET public schema
        $getRequest = new ServerRequest('GET', '/api/v1/public/forms/feedback');
        $getResponse = $controller->get($getRequest, ['handle' => 'feedback']);
        $this->assertSame(200, $getResponse->getStatusCode());

        $getData = json_decode((string) $getResponse->getBody(), true);
        $this->assertSame('feedback', $getData['data']['handle']);
        $this->assertSame('Send Feedback', $getData['data']['submit_label']);
        $this->assertCount(3, $getData['data']['fields']);

        // POST public submission
        $postRequest = (new ServerRequest('POST', '/api/v1/public/forms/feedback/submit'))
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody([
                'name' => 'John',
                'email' => 'john@test.com',
                'message' => 'Great product!',
            ]);

        $postResponse = $controller->submit($postRequest, ['handle' => 'feedback']);
        $this->assertSame(201, $postResponse->getStatusCode());

        $postData = json_decode((string) $postResponse->getBody(), true);
        $this->assertTrue($postData['success']);
        $this->assertSame('Thanks for your feedback!', $postData['message']);
    }
}
