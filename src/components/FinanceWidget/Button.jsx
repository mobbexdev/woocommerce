import { useState } from "react";
import { Select } from "./Select.jsx";
import { PlanCard } from "./PlanCard.jsx";

export function Button({ sources, logo, text, theme, disable }) {
  const [open, setOpen] = useState(false);
  const [selectedGroup, setSelectedGroup] = useState("0");
  const handleOpen = () => setOpen(!open);

  const container = document.getElementById("mbbxFinanceWidget");
  container.addEventListener("click", (e) => {
    handleOpen();
  });

  return (
    <>
      {open && (
        <div className={theme}>
          <div id="mbbxProductModal">
            <div id="mbbx-installments-widget" className={theme}>
              <div
                className="mbbx-installment-widget-header"
                id="mbbx-installment-widget-header"
              >
                <Select sources={sources} setSelectedGroup={setSelectedGroup} />
                <button onClick={handleOpen}>X</button>
              </div>
              <div id="mbbxProductModalBody">
                {sources.map(
                  (source, i) =>
                    source.source.name &&
                    (source.source.reference === selectedGroup ||
                      selectedGroup === "0") && (
                      <PlanCard source={source} i={i} />
                    )
                )}
              </div>
            </div>
          </div>
        </div>
      )}

      <button
        disabled={disable}
        onClick={handleOpen}
        className="mbbx-button"
        id="mbbxProductBtn"
      >
        {logo && <img src={logo} />}
        {text}
      </button>
    </>
  );
}
