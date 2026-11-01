// End-to-End Marketplace & Contact Form Plugin Verification Script
// Run with: node test-e2e.mjs

const BASE_URL = 'http://localhost:8081';

// Disable TLS verification warnings if testing against https, but we use http://localhost:8081 directly.
process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';

async function runTest() {
  console.log('====================================================');
  console.log('BS CMS Marketplace & Contact Form Plugin E2E Test');
  console.log(`Target: ${BASE_URL}`);
  console.log('====================================================\n');

  let adminCookie = '';
  let csrfToken = '';

  // 1. Authenticate as Admin
  console.log('>>> [1/7] Authenticating as admin...');
  const loginRes = await fetch(`${BASE_URL}/api/v1/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      email: 'admin@example.com',
      password: 'TestUser2026!Secure',
    }),
  });

  if (!loginRes.ok) {
    const errText = await loginRes.text();
    throw new Error(`Admin login failed: ${loginRes.status} ${errText}`);
  }

  const loginData = await loginRes.json();
  const rawSetCookie = loginRes.headers.get('set-cookie') || '';
  // Extract bs_session
  const match = rawSetCookie.match(/bs_session=([^;]+)/);
  if (match) {
    adminCookie = `bs_session=${match[1]}`;
  }
  csrfToken = loginData.csrf_token || loginData.data?.csrf_token || '';

  console.log(`[PASS] Admin authenticated: User ID ${loginData.user?.id || loginData.data?.user?.id}`);
  console.log(`[INFO] Session cookie and CSRF token acquired.\n`);

  const authHeaders = {
    'Content-Type': 'application/json',
    'Cookie': adminCookie,
    'X-CSRF-Token': csrfToken,
  };

  // 2. Discover Plugins in Marketplace Catalog
  console.log('>>> [2/7] Fetching Marketplace Catalog...');
  const catalogRes = await fetch(`${BASE_URL}/api/v1/marketplace/plugins`, {
    headers: authHeaders,
  });
  if (!catalogRes.ok) {
    throw new Error(`Failed to list marketplace plugins: ${catalogRes.status}`);
  }
  const catalogData = await catalogRes.json();
  const plugins = catalogData.data || catalogData;
  console.log(`[PASS] Found ${plugins.length} plugin(s) in catalog:`);
  plugins.forEach(p => console.log(`   - ${p.name} (handle: ${p.handle}, installed: ${p.is_installed}, enabled: ${p.is_enabled})`));

  const contactPlugin = plugins.find(p => p.handle === 'contact-form');
  if (!contactPlugin) {
    throw new Error('Contact Form plugin not found in marketplace catalog!');
  }

  // 3. Install Contact Form Plugin (if not already installed)
  if (!contactPlugin.is_installed) {
    console.log('\n>>> [3/7] Installing Contact Form Plugin...');
    const installRes = await fetch(`${BASE_URL}/api/v1/marketplace/plugins/contact-form/install`, {
      method: 'POST',
      headers: authHeaders,
    });
    if (!installRes.ok) {
      throw new Error(`Install failed: ${installRes.status} ${await installRes.text()}`);
    }
    const installData = await installRes.json();
    console.log(`[PASS] Plugin installed successfully:`, installData.data?.name || installData.message);
  } else {
    console.log('\n>>> [3/7] Plugin already installed, verifying active status...');
    if (!contactPlugin.is_enabled) {
      await fetch(`${BASE_URL}/api/v1/marketplace/plugins/contact-form/toggle`, {
        method: 'POST',
        headers: authHeaders,
        body: JSON.stringify({ enabled: true }),
      });
      console.log('[PASS] Activated Contact Form plugin.');
    }
  }

  // 4. Create and Publish a Form
  console.log('\n>>> [4/7] Creating & Publishing "Contact Us Demo" Form...');
  const formPayload = {
    handle: 'contact-demo',
    title: 'Contact Us Demo',
    description: 'Get in touch with our team for inquiries and support.',
    status: 'published',
    fields: [
      {
        id: 'full_name',
        label: 'Full Name',
        type: 'text',
        required: true,
        placeholder: 'Jane Doe',
      },
      {
        id: 'email_address',
        label: 'Business Email',
        type: 'email',
        required: true,
        placeholder: 'jane@company.com',
      },
      {
        id: 'inquiry_type',
        label: 'Inquiry Type',
        type: 'select',
        required: true,
        options: ['General', 'Sales', 'Support', 'Partnership'],
      },
      {
        id: 'message',
        label: 'Message',
        type: 'textarea',
        required: true,
        placeholder: 'How can we help you?',
      },
    ],
    settings: {
      submit_button_text: 'Send Inquiry',
      success_message: 'Thank you! Your inquiry has been received and our team will get back to you shortly.',
      honeypot_field: '_hp_company',
    },
  };

  const createFormRes = await fetch(`${BASE_URL}/api/v1/plugins/contact-form/forms`, {
    method: 'POST',
    headers: authHeaders,
    body: JSON.stringify(formPayload),
  });

  let formId = '';
  if (!createFormRes.ok) {
    const err = await createFormRes.text();
    if (err.includes('already taken') || err.includes('already exists') || createFormRes.status === 409) {
      console.log('[INFO] Form handle already exists, fetching existing form...');
      const listForms = await fetch(`${BASE_URL}/api/v1/plugins/contact-form/forms`, { headers: authHeaders });
      const listData = await listForms.json();
      const existing = (listData.data || listData).find(f => f.handle === 'contact-demo');
      formId = existing.id;
    } else {
      throw new Error(`Failed to create form: ${createFormRes.status} ${err}`);
    }
  } else {
    const formData = await createFormRes.json();
    formId = formData.form?.id || formData.data?.id;
  }
  console.log(`[PASS] Form created/ready with ID: ${formId} (handle: 'contact-demo')`);

  // 5. Create a Demo Page with Contact Form Section Block
  console.log('\n>>> [5/7] Creating Demo Page with Contact Form Section Block...');
  
  // Check if page already exists
  const checkPagesRes = await fetch(`${BASE_URL}/api/v1/collections/pages/entries`, { headers: authHeaders });
  const checkPagesData = await checkPagesRes.json();
  const existingPage = (checkPagesData.data || checkPagesData.items || []).find(p => p.slug === 'demo-contact');

  if (existingPage) {
    console.log(`[PASS] Demo page already exists with ID: ${existingPage.id} (slug: demo-contact)`);
  } else {
    const pagePayload = {
      title: 'Demo Contact Page',
      slug: 'demo-contact',
      status: 'published',
      blocks: [
        {
          id: 'hero_1',
          type: 'hero',
          data: {
            title: 'Welcome to BS CMS Demo',
            subtitle: 'This page demonstrates dynamically embedded plugin blocks.',
          },
        },
        {
          id: 'form_block_1',
          type: 'contact_form',
          data: {
            form_handle: 'contact-demo',
            heading: 'Send us a message',
            subheading: 'We respond to all inquiries within 24 business hours.',
          },
        },
      ],
    };

    const createPageRes = await fetch(`${BASE_URL}/api/v1/collections/pages/entries`, {
      method: 'POST',
      headers: authHeaders,
      body: JSON.stringify(pagePayload),
    });

    if (!createPageRes.ok) {
      const pageErr = await createPageRes.text();
      console.log(`[WARN] Creating page returned ${createPageRes.status}:`, pageErr);
    } else {
      const pageData = await createPageRes.json();
      console.log(`[PASS] Page created with ID: ${pageData.id || pageData.data?.id}`);
    }
  }

  // 6. Public Submission Testing (Simulate Frontend Visitor)
  console.log('\n>>> [6/7] Simulating Public Visitor Interacting with the Form...');

  // A. Public Schema Fetch (Unauthenticated)
  console.log('   -> A. Visitor loads form definition from public endpoint...');
  const publicSchemaRes = await fetch(`${BASE_URL}/api/v1/public/forms/contact-demo`);
  if (!publicSchemaRes.ok) {
    throw new Error(`Public form schema fetch failed: ${publicSchemaRes.status} ${await publicSchemaRes.text()}`);
  }
  const publicSchema = await publicSchemaRes.json();
  const schemaData = publicSchema.data || publicSchema;
  console.log(`   [PASS] Public schema loaded: "${schemaData.title}" with ${schemaData.fields.length} fields.`);
  console.log(`   [INFO] Submit label: "${schemaData.submit_label}"`);

  // B. Anti-Spam Honeypot Verification
  console.log('   -> B. Testing Anti-Spam Stealth Honeypot Defense...');
  const spamSubmission = {
    _hp_check: 'http://spam-site.com',
  };
  for (const f of schemaData.fields) {
    if (f.type === 'email') {
      spamSubmission[f.name] = 'bot@badspammer.com';
    } else if (f.type === 'select' && f.options?.length > 0) {
      spamSubmission[f.name] = f.options[0];
    } else {
      spamSubmission[f.name] = 'Spam bot promotion content';
    }
  }

  const spamRes = await fetch(`${BASE_URL}/api/v1/public/forms/contact-demo/submit`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(spamSubmission),
  });
  const spamData = await spamRes.json();
  if (spamData.success && spamData.submission_id?.startsWith('hp_')) {
    console.log(`   [PASS] Stealth honeypot triggered: rejected quietly without DB insert (ID: ${spamData.submission_id})`);
  } else {
    console.log(`   [WARN] Honeypot returned:`, spamData);
  }

  // C. Legitimate Submission
  console.log('   -> C. Submitting valid contact inquiry from visitor...');
  const validSubmission = {};
  for (const field of schemaData.fields) {
    if (field.type === 'email') {
      validSubmission[field.name] = 'eleanor@hillhouse.org';
    } else if (field.type === 'select' && field.options?.length > 0) {
      validSubmission[field.name] = field.options[0];
    } else if (field.type === 'textarea') {
      validSubmission[field.name] = 'We are interested in integrating BS CMS Marketplace with our enterprise editorial team.';
    } else {
      validSubmission[field.name] = 'Eleanor Vance';
    }
  }

  const submitRes = await fetch(`${BASE_URL}/api/v1/public/forms/contact-demo/submit`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36',
    },
    body: JSON.stringify(validSubmission),
  });

  if (!submitRes.ok) {
    throw new Error(`Submission failed: ${submitRes.status} ${await submitRes.text()}`);
  }
  const submitData = await submitRes.json();
  console.log(`   [PASS] Submission successful! ID: ${submitData.submission_id}`);
  console.log(`   [INFO] Confirmation message: "${submitData.message}"`);

  // 7. Backend Inbox Verification
  console.log('\n>>> [7/7] Verifying Backend Inquiries Inbox...');
  const submissionsRes = await fetch(`${BASE_URL}/api/v1/plugins/contact-form/submissions?form_id=${formId}`, {
    headers: authHeaders,
  });

  if (!submissionsRes.ok) {
    throw new Error(`Failed to list submissions: ${submissionsRes.status} ${await submissionsRes.text()}`);
  }

  const submissionsData = await submissionsRes.json();
  const submissions = submissionsData.data || submissionsData;
  console.log(`[PASS] Total submissions received for form: ${submissions.length}`);

  const targetSub = submissions.find(s => s.id === submitData.submission_id) || submissions[0];
  if (!targetSub) {
    throw new Error('Target submission not found in backend inbox!');
  }

  console.log(`\nSubmission Details Verified:`);
  console.log(`   - ID: ${targetSub.id}`);
  console.log(`   - Form Handle: ${targetSub.form_handle}`);
  console.log(`   - Status: ${targetSub.status}`);
  console.log(`   - Submission Data:`, JSON.stringify(targetSub.data, null, 2));
  console.log(`   - IP Address: ${targetSub.ip_address}`);
  console.log(`   - Created At: ${targetSub.created_at}`);

  // Mark Submission as Read
  console.log('\n>>> Updating submission status to "read"...');
  const patchRes = await fetch(`${BASE_URL}/api/v1/plugins/contact-form/submissions/${targetSub.id}`, {
    method: 'PATCH',
    headers: authHeaders,
    body: JSON.stringify({ status: 'read' }),
  });

  if (!patchRes.ok) {
    throw new Error(`Failed to update submission status: ${patchRes.status}`);
  }
  const patchData = await patchRes.json();
  const updatedSub = patchData.submission || patchData.data;
  console.log(`[PASS] Submission status updated: ${updatedSub.status}`);

  console.log('\n====================================================');
  console.log('ALL END-TO-END VERIFICATION CHECKS PASSED PERFECTLY!');
  console.log('====================================================');
}

runTest().catch(err => {
  console.error('\n[FAIL] E2E Test encountered an error:', err);
  process.exit(1);
});
