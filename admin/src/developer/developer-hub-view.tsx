import { FC } from 'react';
import {
  Breadcrumbs,
  Card,
  CardContent,
  PageContainer,
  PageHeader,
} from '@bs/ui';
import {
  ArrowRight,
  Code2,
  Database,
  ListTree,
} from 'lucide-react';

const TOOLS: Array<{
  to: string;
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  description: string;
}> = [
  {
    to: '/admin/developer/schema',
    icon: Database,
    title: 'Schema Builder',
    description: 'Create and edit content collections, add typed fields, attach taxonomies, and apply reviewed migrations.',
  },
  {
    to: '/admin/developer/jobs',
    icon: ListTree,
    title: 'Background Jobs',
    description: 'Monitor asynchronous tasks, image optimization batches, webhook dispatches, and dead-letter retries.',
  },
  {
    to: '/admin/developer/api',
    icon: Code2,
    title: 'API Explorer',
    description: 'Inspect the OpenAPI specification, registered REST endpoints, and authentication mechanisms.',
  },
];

/** Developer tools hub — links to the developer subsections. */
export const DeveloperHubView: FC = () => {
  return (
    <PageContainer width="full" className="space-y-6">
      <Breadcrumbs
        items={[
          { label: 'Admin', href: '/admin/' },
          { label: 'Developer' },
        ]}
      />
      <PageHeader
        title="Developer Tools"
        description="Schema inspection, background jobs, and API exploration for technical users."
      />
      <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
        {TOOLS.map((tool) => {
          const Icon = tool.icon;
          return (
            <Card
              key={tool.to}
              className="group cursor-pointer transition-colors hover:border-primary/40"
              onClick={() => { window.location.href = tool.to; }}
              data-testid={`dev-tool-${tool.to.split('/').pop()}`}
            >
              <CardContent className="pt-5">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                    <Icon className="h-5 w-5" />
                  </div>
                  <ArrowRight className="h-4 w-4 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                </div>
                <p className="mt-4 text-14 font-semibold text-foreground">{tool.title}</p>
                <p className="mt-1.5 text-13 text-muted-foreground leading-relaxed">{tool.description}</p>
              </CardContent>
            </Card>
          );
        })}
      </div>
    </PageContainer>
  );
};
