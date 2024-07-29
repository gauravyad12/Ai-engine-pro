const { useEffect, useRef, useState } = wp.element;

import CopyButton from '@app/components/CopyButton';
import { useModClasses } from '@app/chatbot/helpers';

const FormOutput = (props) => {
  // eslint-disable-next-line no-unused-vars
  const { system, params, theme } = props;
  const { id, copyButton, className } = params;
  const baseClass = 'mwai-form-field-output';
  const classStr = `${baseClass}${className ? ' ' + className : ''}`;
  const { modCss } = useModClasses(theme);
  const [divContent, setDivContent] = useState(() => 
    divRef?.current?.textContent ? divRef.current.textContent : ''
  );
  const divRef = useRef(null);

  useEffect(() => {
    const observer = new MutationObserver((mutationsList) => {
      for (const mutation of mutationsList) {
        if (mutation.type === 'childList') {
          setDivContent(divRef.current.innerText);
        }
      }
    });
    if (divRef.current) {
      observer.observe(divRef.current, { childList: true, subtree: true });
    }
    return () => observer.disconnect();
  }, []);

  return (
    <div style={{ position: 'relative' }}>
      <div ref={divRef} id={id} className={classStr}>
      </div>
      {copyButton && <CopyButton content={divContent} modCss={modCss} />}
    </div>
  );
};

export default FormOutput;
