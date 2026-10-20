import { NotFoundState } from '@bs/ui';

export function NotFoundRoute() {
  return (
    <div className="flex min-h-[400px] items-center justify-center p-6">
      <NotFoundState
        onBack={() => {
          if (window.history.length > 1) {
            window.history.back();
          } else {
            window.location.href = '/admin';
          }
        }}
        onHome={() => {
          window.location.href = '/admin';
        }}
      />
    </div>
  );
}
