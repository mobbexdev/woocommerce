const PlanTR = ({ name, count, price, amount, description, tags }) => {
  return (
    <tr>
      <td>
        {name}
        <small style={{ fontWeight: "bold" }}>{description}</small>
        {tags &&
          tags.map((tag) => <small>{`${tag.label}: ${tag.value}`}</small>)}
      </td>

      <td style={{ textAlign: "right" }}>
        {price ? `$${price}` : ""}
        {count != 1 && (
          <small>
            {count} cuotas de ${amount}
          </small>
        )}
      </td>
    </tr>
  );
};

const PlanTable = ({ installments }) => {
  return (
    <table className="mbbx-plan-table">
      <tbody>
        {installments.map((installment) => (
          <PlanTR
            name={installment.name}
            count={installment.totals.installment.count}
            price={installment.totals.total}
            amount={installment.totals.installment.amount}
            description={installment.description}
            tags={installment.tags}
          />
        ))}
      </tbody>
    </table>
  );
};

export function PlanCard({ source, i }) {
  return (
    <div id={i + "+" + source.source.reference} className="mbbx-plan-card">
      <p className="mbbx-payment-method">
        <img
          src={`https://res.mobbex.com/images/sources/jpg/${source.source.reference}.jpg`}
        ></img>
        {source.source.name}
      </p>

      {source.installments.list && (
        <PlanTable installments={source.installments.list} />
      )}

      {!source.installments.enabled && (
        <table className="mbbx-plan-table">
          <tbody>
            <PlanTR
              name={source.view.subgroup_title}
              count={1}
              price={null}
              amount={""}
              description={""}
            />
          </tbody>
        </table>
      )}
    </div>
  );
}
