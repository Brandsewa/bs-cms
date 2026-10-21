import { describe, it, expect, vi, beforeEach } from 'vitest';
import { toast } from 'sonner';
import { problemToast } from '../src/feedback/problem-toast';

vi.mock('sonner', () => {
  return {
    toast: {
      error: vi.fn(),
      success: vi.fn(),
    },
  };
});

describe('problemToast', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('formats RFC 9457 error with request id action', () => {
    problemToast({
      title: 'Validation Failed',
      detail: 'The email is already registered.',
      status: 422,
      request_id: 'req_12345',
    });

    expect(toast.error).toHaveBeenCalledWith(
      'Validation Failed: The email is already registered.',
      expect.objectContaining({
        duration: Infinity,
        action: expect.objectContaining({
          label: 'Copy Request ID',
        }),
      })
    );
  });

  it('handles field-level validation errors cleanly', () => {
    problemToast({
      title: 'Invalid Request',
      errors: {
        title: ['Title is required'],
      },
    });

    expect(toast.error).toHaveBeenCalledWith(
      expect.stringContaining('title: Title is required'),
      expect.anything()
    );
  });
});
