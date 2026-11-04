import { FC, useState, useEffect, useCallback } from 'react';
import {
  Breadcrumbs,
  PageContainer,
  PageHeader,
  Button,
  Badge,
  Input,
  Label,
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
  Switch,
  Spinner,
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  Tabs,
  TabsList,
  TabsTrigger,
  TabsContent,
} from '@bs/ui';
import {
  Plus,
  Trash2,
  Code2,
  Eye,
  Mail,
  CheckCircle,
  Inbox,
  Send,
  RefreshCw,
  Copy,
  Check,
  Filter,
} from 'lucide-react';
import { apiClient } from '../lib/api';

export interface FormFieldItem {
  name: string;
  label: string;
  type: string;
  required: boolean;
  placeholder?: string;
  options?: string[];
}

export interface FormItem {
  id: string;
  handle: string;
  title: string;
  description?: string | null;
  status: string;
  fields: FormFieldItem[];
  settings: {
    submit_label?: string;
    success_message?: string;
    notify_emails?: string;
    honeypot_enabled?: boolean;
  };
  submissions_count: number;
  unread_count: number;
  created_at: string;
  updated_at: string;
}

export interface SubmissionItem {
  id: string;
  form_id: string;
  form_handle: string;
  form_title: string;
  data: Record<string, unknown>;
  status: string;
  ip_address?: string | null;
  user_agent?: string | null;
  created_at: string;
}

export const FormsIndexView: FC = () => {
  const [activeTab, setActiveTab] = useState<'forms' | 'submissions'>('forms');
  const [forms, setForms] = useState<FormItem[]>([]);
  const [submissions, setSubmissions] = useState<SubmissionItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedFormFilter, setSelectedFormFilter] = useState<string>('all');
  const [selectedStatusFilter, setSelectedStatusFilter] = useState<string>('all');

  // Modal states
  const [formModalOpen, setFormModalOpen] = useState(false);
  const [editingForm, setEditingForm] = useState<FormItem | null>(null);
  const [formTitle, setFormTitle] = useState('');
  const [formHandle, setFormHandle] = useState('');
  const [formDescription, setFormDescription] = useState('');
  const [formStatus, setFormStatus] = useState('published');
  const [formSubmitLabel, setFormSubmitLabel] = useState('Send Message');
  const [formSuccessMsg, setFormSuccessMsg] = useState('Thank you for reaching out! We will be in touch shortly.');
  const [formNotifyEmails, setFormNotifyEmails] = useState('');
  const [formHoneypot, setFormHoneypot] = useState(true);
  const [formFields, setFormFields] = useState<FormFieldItem[]>([]);

  // Snippet modal
  const [snippetModalOpen, setSnippetModalOpen] = useState(false);
  const [snippetForm, setSnippetForm] = useState<FormItem | null>(null);
  const [copiedSnippet, setCopiedSnippet] = useState(false);

  // Submission detail modal
  const [viewingSub, setViewingSub] = useState<SubmissionItem | null>(null);

  const fetchForms = useCallback(async () => {
    try {
      const res = await apiClient.request<{ data: FormItem[] }>('GET', '/plugins/contact-form/forms');
      if (res.ok && res.data && Array.isArray(res.data.data)) {
        setForms(res.data.data);
      }
    } catch {
      // Keep state
    }
  }, []);

  const fetchSubmissions = useCallback(async () => {
    try {
      const params = new URLSearchParams();
      if (selectedFormFilter !== 'all') params.set('form_id', selectedFormFilter);
      if (selectedStatusFilter !== 'all') params.set('status', selectedStatusFilter);

      const qs = params.toString() ? `?${params.toString()}` : '';
      const res = await apiClient.request<{ data: SubmissionItem[] }>(
        'GET',
        `/plugins/contact-form/submissions${qs}`
      );
      if (res.ok && res.data && Array.isArray(res.data.data)) {
        setSubmissions(res.data.data);
      }
    } catch {
      // Keep state
    }
  }, [selectedFormFilter, selectedStatusFilter]);

  const loadData = useCallback(async () => {
    setLoading(true);
    await Promise.all([fetchForms(), fetchSubmissions()]);
    setLoading(false);
  }, [fetchForms, fetchSubmissions]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const handleOpenCreateForm = () => {
    setEditingForm(null);
    setFormTitle('');
    setFormHandle('');
    setFormDescription('');
    setFormStatus('published');
    setFormSubmitLabel('Send Message');
    setFormSuccessMsg('Thank you for reaching out! We will be in touch shortly.');
    setFormNotifyEmails('');
    setFormHoneypot(true);
    setFormFields([
      { name: 'name', label: 'Full Name', type: 'text', required: true, placeholder: 'Jane Doe' },
      { name: 'email', label: 'Email Address', type: 'email', required: true, placeholder: 'jane@example.com' },
      { name: 'subject', label: 'Subject', type: 'text', required: false, placeholder: 'Inquiry' },
      { name: 'message', label: 'Message', type: 'textarea', required: true, placeholder: 'How can we help?' },
    ]);
    setFormModalOpen(true);
  };

  const handleOpenEditForm = (form: FormItem) => {
    setEditingForm(form);
    setFormTitle(form.title);
    setFormHandle(form.handle);
    setFormDescription(form.description || '');
    setFormStatus(form.status);
    setFormSubmitLabel(form.settings.submit_label || 'Send Message');
    setFormSuccessMsg(form.settings.success_message || 'Thank you! Your message has been sent.');
    setFormNotifyEmails(form.settings.notify_emails || '');
    setFormHoneypot(form.settings.honeypot_enabled !== false);
    setFormFields(form.fields || []);
    setFormModalOpen(true);
  };

  const handleAddField = () => {
    const nextIdx = formFields.length + 1;
    setFormFields([
      ...formFields,
      {
        name: `field_${nextIdx}`,
        label: `Custom Field ${nextIdx}`,
        type: 'text',
        required: false,
        placeholder: '',
      },
    ]);
  };

  const handleRemoveField = (index: number) => {
    setFormFields(formFields.filter((_, idx) => idx !== index));
  };

  const handleUpdateField = (index: number, key: keyof FormFieldItem, val: unknown) => {
    const updated = [...formFields];
    updated[index] = { ...updated[index], [key]: val } as FormFieldItem;
    setFormFields(updated);
  };

  const handleSaveForm = async () => {
    if (!formTitle.trim()) return;

    const payload = {
      title: formTitle,
      handle: formHandle || undefined,
      description: formDescription || undefined,
      status: formStatus,
      fields: formFields,
      settings: {
        submit_label: formSubmitLabel,
        success_message: formSuccessMsg,
        notify_emails: formNotifyEmails,
        honeypot_enabled: formHoneypot,
      },
    };

    if (editingForm) {
      await apiClient.request('PUT', `/plugins/contact-form/forms/${editingForm.id}`, {
        body: payload,
      });
    } else {
      await apiClient.request('POST', '/plugins/contact-form/forms', {
        body: payload,
      });
    }

    setFormModalOpen(false);
    await loadData();
  };

  const handleDeleteForm = async (form: FormItem) => {
    if (confirm(`Delete form "${form.title}" and all its submissions?`)) {
      await apiClient.request('DELETE', `/plugins/contact-form/forms/${form.id}`);
      await loadData();
    }
  };

  const handleStatusChange = async (sub: SubmissionItem, newStatus: string) => {
    await apiClient.request('PATCH', `/plugins/contact-form/submissions/${sub.id}`, {
      body: { status: newStatus },
    });
    if (viewingSub && viewingSub.id === sub.id) {
      setViewingSub({ ...viewingSub, status: newStatus });
    }
    await loadData();
  };

  const handleDeleteSub = async (sub: SubmissionItem) => {
    if (confirm('Delete this submission?')) {
      await apiClient.request('DELETE', `/plugins/contact-form/submissions/${sub.id}`);
      if (viewingSub?.id === sub.id) setViewingSub(null);
      await loadData();
    }
  };

  const totalUnread = forms.reduce((acc, f) => acc + (f.unread_count || 0), 0);

  return (
    <PageContainer width="full" className="space-y-6">
      <Breadcrumbs
        items={[
          { label: 'Admin', href: '/admin/' },
          { label: 'Forms & Inquiries' },
        ]}
      />

      <PageHeader
        title="Forms & Submissions"
        description="Design responsive dynamic forms, embed them anywhere on your site, and view incoming inquiries."
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={loadData} disabled={loading}>
              <RefreshCw className={`mr-2 h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
              Refresh
            </Button>
            <Button size="sm" onClick={handleOpenCreateForm}>
              <Plus className="mr-2 h-4 w-4" />
              Create Form
            </Button>
          </div>
        }
      />

      <Tabs value={activeTab} onValueChange={(v) => setActiveTab(v as 'forms' | 'submissions')} className="space-y-4">
        <TabsList className="border-b">
          <TabsTrigger value="forms" className="gap-2">
            Forms ({forms.length})
          </TabsTrigger>
          <TabsTrigger value="submissions" className="gap-2">
            <Inbox className="h-4 w-4" />
            Inquiries
            {totalUnread > 0 && (
              <Badge variant="default" className="ml-1 bg-emerald-600 text-white text-11 px-1.5 py-0.2">
                {totalUnread} new
              </Badge>
            )}
          </TabsTrigger>
        </TabsList>

        {/* FORMS TAB */}
        <TabsContent value="forms" className="space-y-4">
          {loading ? (
            <div className="flex h-48 items-center justify-center">
              <Spinner className="h-7 w-7 text-primary" />
            </div>
          ) : forms.length === 0 ? (
            <div className="flex flex-col items-center justify-center rounded-lg border border-dashed p-12 text-center">
              <Mail className="h-10 w-10 text-muted-foreground/60" />
              <h3 className="mt-4 text-16 font-semibold">No forms created yet</h3>
              <p className="mt-1.5 text-13 text-muted-foreground max-w-sm">
                Create your first form to start collecting feedback, inquiries, and customer leads.
              </p>
              <Button size="sm" className="mt-5" onClick={handleOpenCreateForm}>
                <Plus className="mr-2 h-4 w-4" />
                Create Form
              </Button>
            </div>
          ) : (
            <div className="overflow-x-auto rounded-lg border">
              <table className="w-full text-left text-13">
                <thead className="border-b bg-muted/40 font-medium text-muted-foreground">
                  <tr>
                    <th className="p-3.5">Form Title</th>
                    <th className="p-3.5">Handle</th>
                    <th className="p-3.5">Status</th>
                    <th className="p-3.5">Fields</th>
                    <th className="p-3.5">Submissions</th>
                    <th className="p-3.5 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {forms.map((form) => (
                    <tr key={form.id} className="hover:bg-muted/20 transition-colors">
                      <td className="p-3.5 font-medium text-foreground">
                        {form.title}
                        {form.description && (
                          <p className="text-12 text-muted-foreground font-normal">{form.description}</p>
                        )}
                      </td>
                      <td className="p-3.5 font-mono text-12 text-muted-foreground">
                        {form.handle}
                      </td>
                      <td className="p-3.5">
                        <Badge variant={form.status === 'published' ? 'default' : 'secondary'} className="text-11">
                          {form.status}
                        </Badge>
                      </td>
                      <td className="p-3.5 text-muted-foreground">
                        {form.fields.length} fields
                      </td>
                      <td className="p-3.5">
                        <div className="flex items-center gap-2">
                          <span>{form.submissions_count} total</span>
                          {form.unread_count > 0 && (
                            <Badge className="bg-emerald-600 text-white text-10">
                              {form.unread_count} unread
                            </Badge>
                          )}
                        </div>
                      </td>
                      <td className="p-3.5 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                              setSnippetForm(form);
                              setSnippetModalOpen(true);
                              setCopiedSnippet(false);
                            }}
                            title="Embed Form on Page"
                          >
                            <Code2 className="h-4 w-4 text-primary" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                              setSelectedFormFilter(form.id);
                              setActiveTab('submissions');
                            }}
                            title="View Submissions"
                          >
                            <Eye className="h-4 w-4" />
                          </Button>
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => handleOpenEditForm(form)}
                          >
                            Edit
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="text-destructive hover:bg-destructive/10"
                            onClick={() => handleDeleteForm(form)}
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </TabsContent>

        {/* SUBMISSIONS TAB */}
        <TabsContent value="submissions" className="space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/20 p-3">
            <div className="flex items-center gap-3">
              <div className="flex items-center gap-1.5 text-12 text-muted-foreground">
                <Filter className="h-3.5 w-3.5" /> Filter by:
              </div>
              <Select value={selectedFormFilter} onValueChange={setSelectedFormFilter}>
                <SelectTrigger className="w-[180px] h-8 text-12">
                  <SelectValue placeholder="All Forms" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Forms</SelectItem>
                  {forms.map((f) => (
                    <SelectItem key={f.id} value={f.id}>
                      {f.title}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>

              <Select value={selectedStatusFilter} onValueChange={setSelectedStatusFilter}>
                <SelectTrigger className="w-[140px] h-8 text-12">
                  <SelectValue placeholder="All Statuses" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Statuses</SelectItem>
                  <SelectItem value="unread">Unread</SelectItem>
                  <SelectItem value="read">Read</SelectItem>
                  <SelectItem value="spam">Spam</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <p className="text-12 text-muted-foreground">
              Showing {submissions.length} submission{submissions.length === 1 ? '' : 's'}
            </p>
          </div>

          {loading ? (
            <div className="flex h-48 items-center justify-center">
              <Spinner className="h-7 w-7 text-primary" />
            </div>
          ) : submissions.length === 0 ? (
            <div className="flex flex-col items-center justify-center rounded-lg border border-dashed p-12 text-center">
              <Inbox className="h-10 w-10 text-muted-foreground/60" />
              <h3 className="mt-4 text-16 font-semibold">No inquiries found</h3>
              <p className="mt-1.5 text-13 text-muted-foreground">
                Submissions made through your public site will appear here in real-time.
              </p>
            </div>
          ) : (
            <div className="overflow-x-auto rounded-lg border">
              <table className="w-full text-left text-13">
                <thead className="border-b bg-muted/40 font-medium text-muted-foreground">
                  <tr>
                    <th className="p-3.5">Status</th>
                    <th className="p-3.5">Form</th>
                    <th className="p-3.5">Sender</th>
                    <th className="p-3.5">Summary / Message</th>
                    <th className="p-3.5">Received</th>
                    <th className="p-3.5 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {submissions.map((sub) => {
                    const senderName = String(sub.data.name || sub.data.customer_name || 'Anonymous');
                    const senderEmail = String(sub.data.email || '');
                    const messagePreview = String(sub.data.message || sub.data.comment || sub.data.inquiry || '');

                    return (
                      <tr
                        key={sub.id}
                        className={`hover:bg-muted/20 transition-colors ${
                          sub.status === 'unread' ? 'bg-primary/5 font-medium' : ''
                        }`}
                      >
                        <td className="p-3.5">
                          {sub.status === 'unread' ? (
                            <Badge className="bg-emerald-600 text-white text-11">Unread</Badge>
                          ) : (
                            <Badge variant="secondary" className="text-11">Read</Badge>
                          )}
                        </td>
                        <td className="p-3.5 font-medium text-foreground">
                          {sub.form_title}
                        </td>
                        <td className="p-3.5">
                          <p className="font-medium text-foreground">{senderName}</p>
                          {senderEmail && (
                            <p className="text-12 text-muted-foreground font-normal">{senderEmail}</p>
                          )}
                        </td>
                        <td className="p-3.5 max-w-xs truncate text-muted-foreground font-normal">
                          {messagePreview || <em>(No message text)</em>}
                        </td>
                        <td className="p-3.5 text-12 text-muted-foreground whitespace-nowrap">
                          {new Date(sub.created_at).toLocaleDateString()} {new Date(sub.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                        </td>
                        <td className="p-3.5 text-right whitespace-nowrap">
                          <div className="flex items-center justify-end gap-1.5">
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={() => {
                                setViewingSub(sub);
                                if (sub.status === 'unread') {
                                  handleStatusChange(sub, 'read');
                                }
                              }}
                            >
                              Inspect
                            </Button>
                            <Button
                              variant="ghost"
                              size="sm"
                              className="text-destructive hover:bg-destructive/10"
                              onClick={() => handleDeleteSub(sub)}
                            >
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </TabsContent>
      </Tabs>

      {/* CREATE / EDIT FORM DIALOG */}
      <Dialog open={formModalOpen} onOpenChange={setFormModalOpen}>
        <DialogContent className="sm:max-w-[650px] max-h-[85vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{editingForm ? 'Edit Form' : 'Create Contact Form'}</DialogTitle>
          </DialogHeader>

          <div className="space-y-5 py-3">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="space-y-1.5">
                <Label htmlFor="form-title">Form Title *</Label>
                <Input
                  id="form-title"
                  placeholder="e.g. Contact Us, Support Ticket"
                  value={formTitle}
                  onChange={(e) => {
                    setFormTitle(e.target.value);
                    if (!editingForm && !formHandle) {
                      setFormHandle(e.target.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''));
                    }
                  }}
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="form-handle">Handle (ID for blocks & snippets)</Label>
                <Input
                  id="form-handle"
                  placeholder="e.g. contact-us"
                  value={formHandle}
                  onChange={(e) => setFormHandle(e.target.value)}
                />
              </div>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="form-desc">Description</Label>
              <Input
                id="form-desc"
                placeholder="Optional short description or instructions"
                value={formDescription}
                onChange={(e) => setFormDescription(e.target.value)}
              />
            </div>

            <div className="flex items-center gap-4">
              <div className="space-y-1.5 flex-1">
                <Label>Publication Status</Label>
                <Select value={formStatus} onValueChange={setFormStatus}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="published">Published (Ready for submissions)</SelectItem>
                    <SelectItem value="draft">Draft (Private / internal only)</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1.5 flex-1">
                <Label htmlFor="form-btn">Submit Button Label</Label>
                <Input
                  id="form-btn"
                  value={formSubmitLabel}
                  onChange={(e) => setFormSubmitLabel(e.target.value)}
                />
              </div>
            </div>

            {/* Field Builder */}
            <div className="space-y-3 rounded-lg border p-4">
              <div className="flex items-center justify-between">
                <div>
                  <h4 className="text-14 font-semibold text-foreground">Form Fields ({formFields.length})</h4>
                  <p className="text-12 text-muted-foreground">Customize the input fields shown to visitors.</p>
                </div>
                <Button variant="outline" size="sm" onClick={handleAddField}>
                  <Plus className="mr-1.5 h-3.5 w-3.5" />
                  Add Field
                </Button>
              </div>

              <div className="space-y-2.5">
                {formFields.map((field, idx) => (
                  <div key={idx} className="flex flex-wrap items-center gap-2 rounded-md border bg-muted/20 p-2.5">
                    <Input
                      placeholder="Field Name / Key"
                      className="w-32 h-8 text-12 font-mono"
                      value={field.name}
                      onChange={(e) => handleUpdateField(idx, 'name', e.target.value)}
                    />
                    <Input
                      placeholder="Label"
                      className="flex-1 h-8 text-12 min-w-[120px]"
                      value={field.label}
                      onChange={(e) => handleUpdateField(idx, 'label', e.target.value)}
                    />
                    <Select
                      value={field.type}
                      onValueChange={(val) => handleUpdateField(idx, 'type', val)}
                    >
                      <SelectTrigger className="w-28 h-8 text-12">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="text">Text</SelectItem>
                        <SelectItem value="email">Email</SelectItem>
                        <SelectItem value="phone">Phone</SelectItem>
                        <SelectItem value="textarea">Textarea</SelectItem>
                        <SelectItem value="checkbox">Checkbox</SelectItem>
                      </SelectContent>
                    </Select>
                    <div className="flex items-center gap-1.5">
                      <Switch
                        id={`req-${idx}`}
                        checked={field.required}
                        onCheckedChange={(checked) => handleUpdateField(idx, 'required', checked)}
                      />
                      <Label htmlFor={`req-${idx}`} className="text-11 cursor-pointer">
                        Req
                      </Label>
                    </div>
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-8 w-8 p-0 text-destructive hover:bg-destructive/10"
                      onClick={() => handleRemoveField(idx)}
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </Button>
                  </div>
                ))}
              </div>
            </div>

            {/* Notification and anti-spam settings */}
            <div className="space-y-3 rounded-lg border p-4">
              <h4 className="text-14 font-semibold text-foreground">Notifications & Security</h4>

              <div className="space-y-1.5">
                <Label htmlFor="notify-email">Notification Email Addresses</Label>
                <Input
                  id="notify-email"
                  placeholder="e.g. info@example.com, sales@example.com"
                  value={formNotifyEmails}
                  onChange={(e) => setFormNotifyEmails(e.target.value)}
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="success-msg">Success Message</Label>
                <Input
                  id="success-msg"
                  value={formSuccessMsg}
                  onChange={(e) => setFormSuccessMsg(e.target.value)}
                />
              </div>

              <div className="flex items-center justify-between pt-1">
                <div className="space-y-0.5">
                  <Label htmlFor="form-hp" className="text-13 font-medium">Honeypot Bot Defense</Label>
                  <p className="text-12 text-muted-foreground">Silently discard automated submissions.</p>
                </div>
                <Switch
                  id="form-hp"
                  checked={formHoneypot}
                  onCheckedChange={setFormHoneypot}
                />
              </div>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setFormModalOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleSaveForm} disabled={!formTitle.trim()}>
              {editingForm ? 'Save Changes' : 'Create Form'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* EMBED SNIPPET MODAL */}
      {snippetForm && (
        <Dialog open={snippetModalOpen} onOpenChange={setSnippetModalOpen}>
          <DialogContent className="sm:max-w-[520px]">
            <DialogHeader>
              <DialogTitle>Embed "{snippetForm.title}"</DialogTitle>
            </DialogHeader>

            <div className="space-y-4 py-2">
              <div className="rounded-lg border bg-primary/5 p-3.5 space-y-2">
                <h4 className="text-13 font-semibold text-primary flex items-center gap-1.5">
                  <CheckCircle className="h-4 w-4" /> Option 1: Page Section Block (Recommended)
                </h4>
                <p className="text-12 text-muted-foreground leading-relaxed">
                  Go to <strong>Entries &rarr; Pages</strong>, open any Page, click <strong>"Add Block"</strong>, select <strong>"Contact Form"</strong>, and enter <code>{snippetForm.handle}</code> as the Form Handle.
                </p>
              </div>

              <div className="space-y-2">
                <h4 className="text-13 font-semibold text-foreground flex items-center gap-1.5">
                  <Code2 className="h-4 w-4" /> Option 2: HTML Embed Snippet
                </h4>
                <p className="text-12 text-muted-foreground leading-relaxed">
                  Paste this snippet into any custom HTML block or template:
                </p>
                <div className="flex items-center justify-between rounded-md border bg-muted/40 p-2.5 font-mono text-12">
                  <span>{`<div data-bs-form="${snippetForm.handle}"></div>`}</span>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 px-2"
                    onClick={() => {
                      navigator.clipboard.writeText(`<div data-bs-form="${snippetForm.handle}"></div>`);
                      setCopiedSnippet(true);
                      setTimeout(() => setCopiedSnippet(false), 2000);
                    }}
                  >
                    {copiedSnippet ? <Check className="h-3.5 w-3.5 text-emerald-600" /> : <Copy className="h-3.5 w-3.5" />}
                  </Button>
                </div>
              </div>
            </div>

            <DialogFooter>
              <Button onClick={() => setSnippetModalOpen(false)}>Done</Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      )}

      {/* INSPECT SUBMISSION MODAL */}
      {viewingSub && (
        <Dialog open={Boolean(viewingSub)} onOpenChange={() => setViewingSub(null)}>
          <DialogContent className="sm:max-w-[560px]">
            <DialogHeader>
              <div className="flex items-center justify-between">
                <DialogTitle>Inquiry Details</DialogTitle>
                <Badge variant={viewingSub.status === 'unread' ? 'default' : 'secondary'}>
                  {viewingSub.status}
                </Badge>
              </div>
            </DialogHeader>

            <div className="space-y-4 py-3">
              <div className="grid grid-cols-2 gap-3 rounded-lg border bg-muted/20 p-3 text-12">
                <div>
                  <span className="text-muted-foreground">Form:</span>
                  <p className="font-semibold text-foreground">{viewingSub.form_title}</p>
                </div>
                <div>
                  <span className="text-muted-foreground">Received:</span>
                  <p className="font-semibold text-foreground">{new Date(viewingSub.created_at).toLocaleString()}</p>
                </div>
                {viewingSub.ip_address && (
                  <div>
                    <span className="text-muted-foreground">IP Address:</span>
                    <p className="font-mono text-foreground">{viewingSub.ip_address}</p>
                  </div>
                )}
                {viewingSub.user_agent && (
                  <div className="col-span-2 truncate">
                    <span className="text-muted-foreground">User Agent:</span>
                    <p className="font-mono text-foreground truncate">{viewingSub.user_agent}</p>
                  </div>
                )}
              </div>

              <div className="space-y-2 rounded-lg border p-4">
                <h4 className="text-13 font-semibold uppercase tracking-wider text-muted-foreground">Submitted Fields</h4>
                <div className="space-y-3 pt-1">
                  {Object.entries(viewingSub.data).map(([key, val]) => (
                    <div key={key} className="space-y-0.5">
                      <span className="text-11 font-medium uppercase tracking-wider text-muted-foreground">{key}</span>
                      <div className="rounded bg-muted/40 p-2 text-13 font-medium text-foreground whitespace-pre-wrap">
                        {String(val || '')}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>

            <DialogFooter className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                {viewingSub.status === 'unread' ? (
                  <Button variant="outline" size="sm" onClick={() => handleStatusChange(viewingSub, 'read')}>
                    Mark as Read
                  </Button>
                ) : (
                  <Button variant="outline" size="sm" onClick={() => handleStatusChange(viewingSub, 'unread')}>
                    Mark as Unread
                  </Button>
                )}
                {Boolean(viewingSub.data.email) && (
                  <Button
                    size="sm"
                    onClick={() => {
                      window.location.href = `mailto:${viewingSub.data.email}?subject=Re: Inquiry on ${viewingSub.form_title}`;
                    }}
                  >
                    <Send className="mr-1.5 h-3.5 w-3.5" />
                    Reply
                  </Button>
                )}
              </div>
              <Button variant="ghost" size="sm" onClick={() => setViewingSub(null)}>
                Close
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      )}
    </PageContainer>
  );
};
