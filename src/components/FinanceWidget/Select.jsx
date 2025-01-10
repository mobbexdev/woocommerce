const Option = ({ id, value, text }) => {
  return (
    <option id={id} value={value}>
      {text}
    </option>
  );
};

export function Select({ sources, setSelectedGroup }) {
  const handleSelectChange = (e) => {
    setSelectedGroup(e.target.value);
  };

  return (
    <select
      className="mbbx-method-select"
      name="mbbx-method-select"
      id="mbbx-method-select"
      onChange={handleSelectChange}
    >
      <Option id="0" text="Seleccione un método de pago" value="0" />
      {sources.map(
        (source, i) =>
          source.source.name &&
          source.installments.enabled && (
            <Option
              id={i + "-" + source.source.reference}
              value={source.source.reference}
              text={source.source.name}
            />
          )
      )}
    </select>
  );
}
