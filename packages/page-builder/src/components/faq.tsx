import type { ComponentConfig } from '@puckeditor/core';

/** One question/answer pair inside a `Faq` block. */
export interface FaqItem {
  question: string;
  answer: string;
}

/** Props contract for the `Faq` block (mirrored by the PHP data migration). */
export interface FaqProps {
  items: FaqItem[];
}

/**
 * Question/answer list rendered as a definition-style list.
 * Rendered markup: `<section class="bs-faq">`.
 */
export const Faq: ComponentConfig<FaqProps> = {
  label: 'FAQ',
  fields: {
    items: {
      type: 'array',
      label: 'Questions',
      arrayFields: {
        question: { type: 'text', label: 'Question' },
        answer: { type: 'textarea', label: 'Answer' },
      },
      defaultItemProps: { question: 'New question', answer: 'The answer.' },
      getItemSummary: (item) => item.question,
    },
  },
  defaultProps: {
    items: [
      { question: 'What is this site built with?', answer: 'A lightweight CMS with a visual page builder.' },
      { question: 'Can I edit these pages later?', answer: 'Yes — every block is editable in the admin editor.' },
    ],
  },
  render: ({ items }) => (
    <section className="bs-faq">
      <ul className="bs-faq__list">
        {items.map((item, index) => (
          <li key={index} className="bs-faq__item">
            <h3 className="bs-faq__question">{item.question}</h3>
            <div className="bs-faq__answer">{item.answer}</div>
          </li>
        ))}
      </ul>
    </section>
  ),
};
