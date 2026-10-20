import { Button, Card, CardContent } from '@bs/ui';
import { Construction, ArrowLeft } from 'lucide-react';

export interface ComingSoonPageProps {
  title: string;
  description: string;
}

export function ComingSoonPage({ title, description }: ComingSoonPageProps) {
  return (
    <div className="flex min-h-[500px] flex-col items-center justify-center p-6 text-center">
      <Card className="max-w-md w-full border-border bg-card p-6 shadow-sm">
        <CardContent className="flex flex-col items-center gap-4 pt-4">
          <div className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary">
            <Construction className="h-6 w-6" />
          </div>
          <div className="space-y-2">
            <h1 className="text-xl font-bold tracking-tight text-foreground">{title}</h1>
            <p className="text-13 text-muted-foreground leading-relaxed">{description}</p>
          </div>
          <div className="pt-2">
            <Button asChild variant="outline" size="sm" className="gap-2">
              <a href="/admin/">
                <ArrowLeft className="h-4 w-4" />
                Back to dashboard
              </a>
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
