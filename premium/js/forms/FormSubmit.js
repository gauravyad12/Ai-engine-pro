// React & Vendor Libs
const { render, useEffect, useRef, useState, useMemo } = wp.element;

// AI Engine
import { mwaiHandleRes, mwaiFetch, OutputHandler } from '@app/helpers';

const errorsContainer = {
  background: '#711f1f',
  color: '#fff',
  padding: '15px 30px',
  borderRadius: '10px',
  margin: '10px 0 0 0'
};

const FormSubmit = (props) => {
  // eslint-disable-next-line no-unused-vars
  const { system, params, theme } = props;
  const [ isLoading, setIsLoading ] = useState(false);
  const [ isValid,  setIsValid ] = useState(false);
  const [ fields, setFields ] = useState({});
  const refSubmit = useRef(null);
  const refContainer = useRef(null);
  const [ serverReply, setServerReply ] = useState();
  const [ errors, setErrors ] = useState([]);

  // System Params
  const { id,  stream = false, formId, sessionId, contextId, restNonce, restUrl, debugMode } = system;
  
  // Front Params
  const { label, outputElement, inputs } = params;

  const isFieldValid = (inputElement, value) => {
    const isValid = !(inputElement.required && (!value || value === ''));
    return isValid;
  };

  const getValue = (inputElement, currentVal = null) => {
    const key = inputElement.field ?? inputElement.selector;
    let newVal = null;
    if (!inputElement.field) {
      // It's a Selector Input, let's check for checkboxes.
      const checkboxes = [...inputElement.element.querySelectorAll('input[type="checkbox"]')];
      if (checkboxes.length > 0) {
        inputElement.subType = 'checkbox';
      }
      // It's a Selector Input, let's check for radios.
      const radios = [...inputElement.element.querySelectorAll('input[type="radio"]')];
      if (radios.length > 0) {
        inputElement.subType = 'radio';
      }
      // Check if it's a select
      const select = inputElement.element.querySelector('select');
      if (select) {
        inputElement.subType = 'select';
      }
    }

    if (inputElement.subType === 'checkbox') {
      const checkboxes = [...inputElement.element.querySelectorAll('input[type="checkbox"]')];
      newVal = checkboxes.filter(checkbox => checkbox.checked).map(checkbox => checkbox.value);
      if (debugMode) { 
        // eslint-disable-next-line no-console
        console.log(`AI Forms: Form ${id} => Checkbox Updated`, { 
          key, newVal, currentVal, subType: inputElement.subType, inputElement
        });
      }
    }
    else if (inputElement.subType === 'radio') {
      const radios = [...inputElement.element.querySelectorAll('input[type="radio"]')];
      const radio = radios.find(radio => radio.checked);
      newVal = radio ? radio.value : null;
      if (debugMode) { 
        // eslint-disable-next-line no-console
        console.log(`AI Forms: Form ${id} => Radio Updated`, { 
          key, newVal, currentVal, subType: inputElement.subType, inputElement
        });
      }
    }
    else if (inputElement.subType === 'select') {
      const select = inputElement.element.querySelector('select');
      newVal = select.value;
      if (debugMode) {
        // eslint-disable-next-line no-console
        console.log(`AI Forms: Form ${id} => Select Updated`, {
          key, newVal, currentVal, subType: inputElement.subType, inputElement
        });
      }
    }
    else if (inputElement.field) {
      const input = inputElement.element.querySelector(inputElement.subType);
      newVal = input.value;
      if (debugMode) { 
        // eslint-disable-next-line no-console
        console.log(`AI Forms: Form ${id} => Field Updated`, { 
          key, newVal, currentVal, subType: inputElement.subType, inputElement
        });
      }
    }
    else if (inputElement.selector) {
      newVal = inputElement.element.textContent.trim();
      if (!newVal) {
        newVal = inputElement.element.value;
      }
      if (debugMode) { 
        // eslint-disable-next-line no-console
        console.log(`AI Forms: Form ${id} => Selector Updated`, {
          key, newVal, currentVal, subType: inputElement.subType, inputElement
        });
      }
    }
    else {
      console.error("AI Forms: Cannot recognize the changes on this inputElement.", { key, currentVal, inputElement });
    }
    return newVal;
  };

  useEffect(() => {
    const handlePageLoad = () => {
      const container = refSubmit.current.closest('.mwai-form-container');
      if (!inputs || (!inputs.selectors.length && !inputs.fields.length)) {
        setErrors(errors => [...errors, "The 'Inputs' are not defined."]);
        return;
      }
      refContainer.current = container;
      const inputElements = [];
      inputs.selectors.forEach(selector => {
        const element = document.querySelector(selector);
        if (!element) {
          setErrors(errors => [...errors, `The 'Input Field' (selector) was not found (${selector}).`]);
          return;
        }
        let required = element.getAttribute('data-form-required') === 'true';
        if (!required) {
          const requiredElement = element.querySelector('[required]');
          if (requiredElement) {
            required = true;
          }
        }
        inputElements.push({ selector, element, required });
      });
      inputs.fields.forEach(field => {
        const element = refContainer.current.querySelector(`fieldset[data-form-name='${field}']`);
        if (!element) {
          //alert(`The 'Input Field' (element) was not found (${field}).`);
          setErrors(errors => [...errors, `The 'Input Field' (element) was not found (${field}).`]);
          return;
        }
        const subType = element.getAttribute('data-form-type');
        let required = element.getAttribute('data-form-required') === 'true';
        if (!required) {
          const requiredElement = element.querySelector('[required]');
          if (requiredElement) {
            required = true;
          }
        }
        inputElements.push({ field, subType, element, required });
      });

      // Set Fields
      const freshFields = {};
      inputElements.forEach(inputElement => {
        const key = inputElement.field ?? inputElement.selector;
        const value = getValue(inputElement);
        freshFields[key] = {
          value: value,
          isValid: isFieldValid(inputElement, value),
          isRequired: inputElement.required
        };
      });
      setFields(freshFields);

      // Set Event Listeners
      inputElements.forEach(inputElement => {
        inputElement.element.addEventListener('change', () => { onInputElementChanged(inputElement); });
        inputElement.element.addEventListener('keyup', () => { onInputElementChanged(inputElement); });
        if (inputElement.selector) {
          const observer = new MutationObserver(() => { onInputElementChanged(inputElement); });
          observer.observe(inputElement.element, { childList: true, subtree: true });
        }        
      });
    };

    // Check if the document has already loaded, if so, run the function directly.
    if (document.readyState === 'complete') {
      handlePageLoad();
    }
    else {
      // Otherwise, wait for the page to load.
      window.addEventListener('load', handlePageLoad);
    }

    // Cleanup
    return () => {
      window.removeEventListener('load', handlePageLoad);
    };
  }, [inputs]);

  // Update the content of the fields.
  const onInputElementChanged = async (inputElement) => {
    const key = inputElement.field ?? inputElement.selector;
    const currentVal = fields[key]?.value ?? null;
    const newVal = getValue(inputElement, currentVal);
    const hasChanges = currentVal !== newVal;

    if (hasChanges) {
      setFields(prev => ({ ...prev,
        [key]: { 
          value: newVal,
          isValid: isFieldValid(inputElement, newVal),
          isRequired: inputElement.required
        }
      }));
    }
  };

  useEffect(() => {
    if (Object.keys(fields).length === 0) { return; }
    if (debugMode) { 
      // eslint-disable-next-line no-console
      console.log('Fields Updated', fields);
    }
    const freshIsValid = Object.values(fields).every(field => field.isValid);
    setIsValid(freshIsValid);
  }, [fields]);

  useEffect(() => {
    const output = document.querySelector(outputElement);
    if (!serverReply) { return; }
    if (!output) { 
      if (!errors.includes(`The 'Output' was not found (${outputElement ?? 'N/A'}).`)) {
        setErrors(errors => [...errors, `The 'Output' was not found (${outputElement ?? 'N/A'}).`]);
      }
      return;
    }
    // if output is a input or a textarea, let's write the reply there.
    if (output.tagName === 'INPUT' || output.tagName === 'TEXTAREA') {
      output.value = serverReply.reply;
      return;
    }
    else {
      const { success, reply, message } = serverReply;
      if (success) {
        render(<OutputHandler baseClass="mwai-form-output" content={reply}
          isStreaming={isLoading && stream} />, output);
      }
      else {
        render(<OutputHandler baseClass="mwai-form-output" error={message}
          isStreaming={isLoading && stream} />, output);
      }
    }
  }, [isLoading, stream, serverReply]);

  const onSubmitClick = async () => {
    setIsLoading(true);
    setServerReply({ success: true, reply: '' });

    // Convert the fields into a format that the server can understand, [key] = value.
    const dataFields = {};
    Object.keys(fields).forEach(key => {
      dataFields[key] = fields[key].value;
    });

    const body = {
      id: id,
      formId: formId,
      session: sessionId,
      contextId: contextId,
      stream,
      fields: dataFields
    };

    try {
      if (debugMode) { 
        // eslint-disable-next-line no-console
        console.log('[FORMS] OUT: ', body);
      }
      const streamCallback = !stream ? null : (content) => {
        setServerReply({ success: true, reply: content });
      };

      // Let's perform the request. The mwaiHandleRes will handle the complexity of response.
      const res = await mwaiFetch(`${restUrl}/mwai-ui/v1/forms/submit`, body, restNonce, stream);
      const data = await mwaiHandleRes(res, streamCallback, debugMode ? "FORMS" : null);
      setServerReply(data);
      if (debugMode) {
        // eslint-disable-next-line no-console
        console.log('[FORMS] IN: ', data);
      }
    }
    catch (err) {
      console.error("An error happened in the handling of the forms response.", { err });
    }
    finally {
      setIsLoading(false);
    }
  };

  const baseClasses = useMemo(() => {
    const classes = ['mwai-form-submit'];
    if (isLoading) {
      classes.push('mwai-loading');
    }
    return classes;
  }, [isLoading]);

  return (
    <div ref={refSubmit} className={baseClasses.join(' ')}>
      <button id={id} disabled={!isValid || isLoading} onClick={onSubmitClick}>
        <span>{label}</span>
      </button>
      {errors.length > 0 && (
        <ul className="mwai-forms-errors" style={errorsContainer}>
          {errors.map((error, index) => (
            <li key={index}>{error}</li>
          ))}
        </ul>
      )}
    </div>
  );
};

export default FormSubmit;
