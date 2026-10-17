import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import DragHandle from '@tiptap/extension-drag-handle';

const t0 = performance.now();

// Generate a realistic 10,000-word document content
function generate10kDoc() {
  const paragraphs: any[] = [
    {
      type: 'heading',
      attrs: { level: 1 },
      content: [{ type: 'text', text: 'BS CMS High-Performance Editor Spike (10,000 Words)' }]
    }
  ];

  const sampleParagraph = 
    "BS CMS delivers a modern, lightweight headless content management platform engineered for ordinary shared hosting environments with PHP 8.2 and MySQL 8 or MariaDB 10.6. The architectural decisions prioritize security, verified platform isolation, predictable latency profiles, and developer ergonomics. By maintaining standard ProseMirror document schemas and rendering directly to semantic HTML and Markdown, BS CMS ensures that public web properties and autonomous AI retrieval engines receive canonical, authoritative content without client-side rendering bottlenecks or server daemon dependencies.";

  // ~70 words per paragraph. 145 paragraphs ~ 10,150 words.
  for (let i = 1; i <= 145; i++) {
    if (i % 15 === 0) {
      paragraphs.push({
        type: 'heading',
        attrs: { level: 2 },
        content: [{ type: 'text', text: `Section ${i / 15}: Advanced System Architecture and Benchmarking` }]
      });
      paragraphs.push({
        type: 'image',
        attrs: {
          src: 'https://images.unsplash.com/photo-1555066931-4365d14bab8c?auto=format&fit=crop&w=800&q=80',
          alt: `Architecture diagram ${i / 15}`
        }
      });
    }
    paragraphs.push({
      type: 'paragraph',
      content: [
        { type: 'text', text: `[Paragraph ${i}] ` },
        { type: 'text', text: sampleParagraph }
      ]
    });
  }

  return {
    type: 'doc',
    content: paragraphs
  };
}

const docData = generate10kDoc();

// Initialize editor
const editor = new Editor({
  element: document.querySelector('#editor')!,
  extensions: [
    StarterKit,
    Image,
    DragHandle
  ],
  content: docData,
  autofocus: true,
  onUpdate({ editor }) {
    updateWordCount(editor);
  }
});

const tti = performance.now() - t0;
console.log(`Editor initialized in ${tti.toFixed(2)} ms`);

// Metrics display
function updateWordCount(ed: Editor) {
  const text = ed.getText();
  const words = text.trim().split(/\s+/).filter(Boolean).length;
  const countEl = document.getElementById('word-count');
  if (countEl) countEl.innerText = `${words.toLocaleString()} words`;
  return words;
}

const words = updateWordCount(editor);
const ttiEl = document.getElementById('tti');
if (ttiEl) ttiEl.innerText = `${tti.toFixed(2)} ms`;

// Measure typing latency over 50 simulated keystrokes
async function benchmarkTypingLatency(ed: Editor) {
  const sampleLatencies: number[] = [];
  const chars = 'abcdefghijklmnopqrstuvwxyz ';

  for (let i = 0; i < 50; i++) {
    const char = chars[i % chars.length];
    const start = performance.now();
    ed.commands.insertContent(char);
    // Force DOM update
    await new Promise(resolve => requestAnimationFrame(resolve));
    const duration = performance.now() - start;
    sampleLatencies.push(duration);
  }

  sampleLatencies.sort((a, b) => a - b);
  const p50 = sampleLatencies[Math.floor(sampleLatencies.length * 0.5)];
  const p95 = sampleLatencies[Math.floor(sampleLatencies.length * 0.95)];
  const avg = sampleLatencies.reduce((a, b) => a + b, 0) / sampleLatencies.length;

  console.log(`Typing Latency: avg=${avg.toFixed(2)}ms, p50=${p50.toFixed(2)}ms, p95=${p95.toFixed(2)}ms`);
  const latEl = document.getElementById('typing-latency');
  if (latEl) latEl.innerText = `p50: ${p50.toFixed(2)} ms | p95: ${p95.toFixed(2)} ms (avg: ${avg.toFixed(2)} ms)`;

  (window as any).__BENCHMARK_RESULTS__ = {
    words,
    tti: Number(tti.toFixed(2)),
    typingLatency: {
      p50: Number(p50.toFixed(2)),
      p95: Number(p95.toFixed(2)),
      avg: Number(avg.toFixed(2))
    }
  };
}

// Bubble menu logic
const bubbleMenu = document.getElementById('bubble-menu')!;
editor.on('selectionUpdate', ({ editor }) => {
  const { from, to } = editor.state.selection;
  if (from === to) {
    bubbleMenu.style.display = 'none';
    return;
  }
  bubbleMenu.style.display = 'flex';
  const coords = editor.view.coordsAtPos(from);
  bubbleMenu.style.top = `${coords.top - 40}px`;
  bubbleMenu.style.left = `${coords.left}px`;
});

document.getElementById('btn-bold')?.addEventListener('click', () => editor.chain().focus().toggleBold().run());
document.getElementById('btn-italic')?.addEventListener('click', () => editor.chain().focus().toggleItalic().run());
document.getElementById('btn-strike')?.addEventListener('click', () => editor.chain().focus().toggleStrike().run());
document.getElementById('btn-code')?.addEventListener('click', () => editor.chain().focus().toggleCode().run());

// Run latency benchmark after mount
setTimeout(() => {
  benchmarkTypingLatency(editor);
}, 500);
