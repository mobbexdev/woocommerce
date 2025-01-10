const { useEffect } = window.wp.element;
const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const settings = window.wc.wcSettings.getSetting("mobbex_data", {});
const { decodeEntities } = window.wp.htmlEntities;

/**
 * Label component
 *
 * @param {*} props Props from payment API.
 */
const Label = ({ components }) => {
  const { PaymentMethodLabel } = components;

  return (
    <>
      {settings.payment_method_image != "" && (
        <img
          src={settings.payment_method_image}
          style={{ marginRight: "5px" }}
        />
      )}
      <PaymentMethodLabel text={settings.title} />
    </>
  );
};

const Content = () => {
  return (
    <>
      {settings.description != "" && <p>{settings.description}</p>}
      {settings.checkout_banner != "" && (
        <img
          src={settings.checkout_banner}
          alt="mobbex metodos de pagos"
          style={{ maxWidth: "100%", borderRadius: "0%" }}
        />
      )}
    </>
  );
};

const PaymentMethod = ({ eventRegistration }) => {
  const { onCheckoutSuccess } = eventRegistration;

  useEffect(() => {
    return onCheckoutSuccess((response) => {
      if (response.redirect) return redirectToCheckout(response.redirect);
      else return openCheckoutModal(response.processingResponse.paymentDetails);
    });
  }, [onCheckoutSuccess]);

  return <Content />;
};

/**
 * Redirect to Mobbex checkout page.
 *
 * @param {array} response Mobbex checkout response.
 */
function redirectToCheckout(redirect) {
  window.top.location = redirect;
  return true;
}

/**
 * Open the Mobbex checkout modal.
 *
 * @param {array} response Mobbex checkout response.
 */
function openCheckoutModal(response) {
  let options = {
    id: response.checkout_id,
    type: "checkout",

    onResult: (data) => {
      location.href =
        response.return_url +
        "&fromCallback=onResult&status=" +
        data.status.code;
    },

    onPayment: (data) => {
      mbbxPaymentData = data.data;
    },

    onClose: (cancelled) => {
      location.href =
        response.return_url +
        "&fromCallback=onClose&status=" +
        (mbbxPaymentData ? mbbxPaymentData.status.code : "500");
    },

    onError: (error) => {
      location.href =
        response.return_url +
        "&fromCallback=onError&status=" +
        (mbbxPaymentData ? mbbxPaymentData.status.code : "500");
    },
  };

  let mobbexEmbed = window.MobbexEmbed.init(options);
  mobbexEmbed.open();

  return true;
}

/** Register the Mobbex method */
registerPaymentMethod({
  name: "mobbex",
  paymentMethodId: "mobbex",
  label: <Label />,
  content: <PaymentMethod />,
  edit: <PaymentMethod />,
  canMakePayment: () => true,
  ariaLabel: "Mobbex",
  supports: {
    features: ["products", "refunds"],
  },
});
