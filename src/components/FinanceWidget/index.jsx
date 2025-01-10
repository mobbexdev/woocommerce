import { useState, useEffect } from "react";
import { Button } from "./Button";
import { FinanceWidget } from "@mobbex/ecommerce-ui";

(function (window, $) {
  const { createRoot } = window.ReactDOM;

  function Widget({ $ }) {
    const [sources, setSources] = useState([]);
    const [ready, setReady] = useState(0);

    const updateSources = (price, id, child = true) => {
      setReady(false);
      $.ajax({
        dataType: "json",
        method: "POST",
        url: mobbexWidget.updateUrl,
        data: {
          id: id,
          price: price,
          child: child,
        },
        success: (response) => {
          setReady(true);
          setSources(JSON.parse(response));
        },
        error: (error) => {
          console.log(error);
        },
      });
    };

    useEffect(() => {
      // Get sources and payment method selector
      $.ajax({
        dataType: "json",
        method: "POST",
        url: mobbexWidget.sourcesUrl,
        data: {
          ids: mobbexWidget.product_ids,
          price: mobbexWidget.price,
        },
        success: (response) => {
          setSources(JSON.parse(response));
          setReady(true);
        },
        error: (error) => {
          console.log(error);
        },
      });

      //Trigger widget update when selected variation change
      $(document).on("found_variation", "form.cart", function (e, variation) {
        updateSources(variation.display_price, variation.variation_id);
      });

      //Updates widget when component change in woocommerce composite products
      $(document).on(
        "wc-composite-component-loaded",
        "form.cart",
        (e, data) => {
          let total =
            data.composite.composite_price_view.model.attributes.totals.price;
          updateSources(total, data.composite.composite_id, false);
        }
      );
    }, []);

    return mobbexWidget.type === "embed" ? (
      <FinanceWidget
        sources={sources}
        theme={mobbexWidget.theme}
        ready={ready}
      />
    ) : (
      <Button
        disable={!ready}
        sources={sources}
        text={mobbexWidget.text}
        logo={mobbexWidget.logo}
        theme={mobbexWidget.theme}
      />
    );
  }

  async function renderWidget() {
    // Wait for the container to be available
    let counter = 0;
    do {
      await new Promise((resolve) => setTimeout(resolve, 100));
      counter++;
    } while (!document.querySelector("#mbbxFinanceWidget") || counter > 50);

    //Create the root or return if container doesn't exists
    const container = document.querySelector("#mbbxFinanceWidget");
    if (!container) return console.error("Mobbex widget container not found");
    const root = createRoot(container);

    //Render the widget
    root.render(<Widget $={$} />);
  }

  $(document).ready(renderWidget());
})(window, jQuery);
