import { FC, useState, useEffect } from 'react';
import {
  Badge,
  Breadcrumbs,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  PageContainer,
  PageHeader,
  Spinner,
} from '@bs/ui';
import { FileCode, ExternalLink, Copy, Check } from 'lucide-react';
import { apiClient } from '../lib/api';

export const ApiExplorerView: FC = () => {
  const [spec, setSpec] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    fetch('/api/v1/openapi.json')
      .then((res) => res.json())
      .then((data) => setSpec(data))
      .catch(() => setSpec(null))
      .finally(() => setLoading(false));
  }, []);

  const handleCopySpec = () => {
    if (spec) {
      navigator.clipboard.writeText(JSON.stringify(spec, null, 2));
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  };

  const paths = spec?.paths ? Object.keys(spec.paths) : [];

  return (
    <PageContainer width="full" className="space-y-6">
      <Breadcrumbs
        items={[
          { label: 'Admin', href: '/admin/' },
          { label: 'Developer', href: '/admin/developer' },
          { label: 'API Explorer' },
        ]}
      />

      <PageHeader
        title="OpenAPI / REST API Explorer"
        description="Inspect schemas, registered endpoints, authentication mechanisms, and OpenAPI 3.1 specification documents."
        actions={
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => window.open('/api/v1/openapi.json', '_blank')}
            >
              <ExternalLink className="mr-1.5 h-3.5 w-3.5" /> Raw openapi.json
            </Button>
            <Button variant="default" size="sm" onClick={handleCopySpec} disabled={!spec}>
              {copied ? <Check className="mr-1.5 h-3.5 w-3.5" /> : <Copy className="mr-1.5 h-3.5 w-3.5" />}
              {copied ? 'Copied' : 'Copy Spec JSON'}
            </Button>
          </div>
        }
      />

      {loading ? (
        <div className="flex min-h-[250px] flex-col items-center justify-center gap-3">
          <Spinner size={28} />
          <p className="text-13 text-muted-foreground">Loading OpenAPI definitions...</p>
        </div>
      ) : (
        <div className="space-y-4">
          <Card>
            <CardHeader className="py-3.5">
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle className="text-15">API Contract Overview</CardTitle>
                  <CardDescription>
                    {spec?.info?.title || 'Brand Sewa CMS Core API'} — version {spec?.info?.version || '1.0.0'}
                  </CardDescription>
                </div>
                <Badge variant="secondary" className="font-mono text-11">
                  {paths.length} Endpoints Registered
                </Badge>
              </div>
            </CardHeader>
          </Card>

          <div className="divide-y divide-border rounded-md border border-border bg-card">
            {paths.map((pathKey) => {
              const pathItem = spec.paths[pathKey];
              const methods = Object.keys(pathItem);

              return (
                <div key={pathKey} className="p-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                  <div className="space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                      {methods.map((m) => {
                        const mUpper = m.toUpperCase();
                        const variant =
                          mUpper === 'GET'
                            ? 'default'
                            : mUpper === 'POST'
                            ? 'secondary'
                            : mUpper === 'DELETE'
                            ? 'destructive'
                            : 'outline';
                        return (
                          <Badge key={m} variant={variant as any} className="font-mono text-[10px] font-bold">
                            {mUpper}
                          </Badge>
                        );
                      })}
                      <span className="font-mono text-13 font-semibold text-foreground">{pathKey}</span>
                    </div>
                    {pathItem[methods[0]]?.summary && (
                      <p className="text-12 text-muted-foreground">{pathItem[methods[0]].summary}</p>
                    )}
                  </div>

                  <span className="text-11 font-mono text-muted-foreground self-end sm:self-center">
                    {pathItem[methods[0]]?.tags?.join(', ') || 'Core'}
                  </span>
                </div>
              );
            })}
          </div>
        </div>
      )}
    </PageContainer>
  );
};
