const FormField = (props) => {
  // eslint-disable-next-line no-unused-vars
  const { system, params, theme } = props;
  const { id, label, type, name, options, required, placeholder,
    default: defaultValue, maxlength, rows, className } = params;
  const parsedOptions = options ? JSON.parse(decodeURIComponent(options)) : null;
  const baseClass = 'mwai-form-field mwai-form-field-' + type;
  const classStr = `${baseClass}${className ? ' ' + className : ''}`;
  const isRequired = required === true;

  switch (type) {
  case 'select':
    return (
      <fieldset id={id} className={classStr} data-form-name={name}
        data-form-type='select' data-form-required={isRequired}>
        <legend>{label}</legend>
        <div className="mwai-form-field-container">
          <select name={name} required={required === 'yes'} data-form-required={isRequired}>
            {parsedOptions && parsedOptions.map(option => (
              <option key={option.value} value={option.value}>{option.label}</option>
            ))}
          </select>
        </div>
      </fieldset>
    );
  case 'radio':
    return (
      <fieldset id={id} className={classStr} data-form-name={name}
        data-form-type='radio' data-form-required={isRequired}>
        <legend>{label}</legend>
        {parsedOptions && parsedOptions.map(option => (
          <div className="mwai-form-field-container" key={option.value}>
            <input type="radio" name={name} value={option.value} required={required === 'yes'} data-form-required={isRequired} />
            <label>{option.label}</label>
          </div>
        ))}
      </fieldset>
    );
  case 'checkbox':
    return (
      <fieldset id={id} className={classStr} data-form-name={name}
        data-form-type='checkbox' data-form-required={isRequired}>
        <legend>{label}</legend>
        {parsedOptions && parsedOptions.map(option => (
          <div className="mwai-form-field-container" key={option.value}>
            <input id={id} type="checkbox" name={name} value={option.value} required={required === 'yes'} data-form-required={isRequired} />
            <label>{option.label}</label>
          </div>
        ))}
      </fieldset>
    );
  case 'textarea':
    return (
      <fieldset className={classStr} data-form-name={name}
        data-form-type='textarea'  data-form-required={isRequired}>
        <legend>{label}</legend>
        <div className="mwai-form-field-container">
          <textarea id={id} name={name} placeholder={placeholder} maxLength={maxlength} rows={rows}
            required={required === 'yes'}  data-form-required={isRequired} defaultValue={defaultValue} />
        </div>
      </fieldset>
    );
  default:
    return (
      <fieldset className={classStr} data-form-name={name}
        data-form-type='input'  data-form-required={isRequired}>
        <legend>{label}</legend>
        <div className="mwai-form-field-container">
          <input id={id} type="text" name={name} placeholder={placeholder} maxLength={maxlength}
            data-form-required={isRequired} defaultValue={defaultValue} required={required === 'yes'} />
        </div>
      </fieldset>
    );
  }
};

export default FormField;
