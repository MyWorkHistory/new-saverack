/**
 * Portal welcome preference sections (field labels, help text, options).
 * Keys must match App\Support\PortalOnboardingSectionRegistry allowed values.
 */

export const PORTAL_ONBOARDING_SECTION_IDS = [
  "communication_preferences",
  "branding_information",
  "order_handling_preferences",
  "packing_slips_preferences",
  "shipping_carrier_preferences",
  "returns_handling_preferences",
  "inventory_sync",
];

export const PORTAL_ONBOARDING_SECTIONS = [
  {
    id: "communication_preferences",
    modalTitle: "Communication Preferences",
    intro:
      "Let us know how you would like us to communicate with you regarding your orders, deliveries, inventory, and any other fulfillment-related updates.",
    communicationOptions: [
      {
        value: "whatsapp",
        title: "WhatsApp Group Chat (Recommended)",
        description:
          "We recommend using WhatsApp as the primary communication channel. It enables faster responses and real-time communication without lengthy email threads. Please enter your phone number so we can create a group chat with your account management team.",
      },
      {
        value: "slack",
        title: "Slack",
        description:
          "We can also communicate through Slack for quick and organized communication. Please provide the email address you would like us to send the Slack workspace invitation to.",
      },
      {
        value: "email",
        title: "Email",
        description:
          "We can communicate via email if you prefer. Please note that email responses may take a few hours, and communication may not be as fast as WhatsApp or Slack.",
      },
    ],
    fields: [
      {
        key: "communication_method",
        type: "select",
        label: "Communication Method",
        required: true,
        options: [
          { value: "whatsapp", label: "WhatsApp Group Chat (Recommended)" },
          { value: "slack", label: "Slack" },
          { value: "email", label: "Email" },
        ],
      },
      {
        key: "whatsapp_phone",
        type: "text",
        label: "Phone Number",
        help: "Enter your phone number so we can create a WhatsApp group chat.",
        required: true,
        showWhen: { field: "communication_method", value: "whatsapp" },
      },
      {
        key: "slack_email",
        type: "text",
        label: "Slack Invite Email",
        help: "Enter the email address we should invite to our Slack workspace.",
        required: true,
        showWhen: { field: "communication_method", value: "slack" },
      },
      {
        key: "contact_email",
        type: "text",
        label: "Email",
        help: "Enter your preferred email address for communication.",
        required: true,
        showWhen: { field: "communication_method", value: "email" },
      },
    ],
  },
  {
    id: "branding_information",
    modalTitle: "Branding Information",
    fields: [
      {
        key: "brand_name",
        type: "text",
        label: "Brand Name",
        help: "Your Brand Name will appear on shipping labels, packing slips, and customer-facing order documents sent with each shipment.",
        required: true,
      },
      {
        key: "logo",
        type: "file",
        label: "Logo Upload",
        help: "Upload an optional logo to display on your packing slips and other customer-facing shipment documents. Recommended height should not exceed 200px. Only jpg and png are accepted.",
        required: false,
      },
      {
        key: "branded_packaging",
        type: "select",
        label: "Branded Packaging Materials",
        help: "Let us know if you will be providing branded packaging materials such as custom boxes, poly mailers, tape, inserts, or other branded shipping supplies.",
        required: true,
        options: [
          { value: "no", label: "No" },
          { value: "yes_will_provide", label: "I will provide branded packaging materials" },
        ],
      },
      {
        key: "custom_inserts",
        type: "select",
        label: "Custom Inserts",
        help: "Will you be including marketing inserts, thank you cards, flyers, samples, or coupons in shipments?",
        required: true,
        options: [
          { value: "no", label: "No" },
          { value: "yes_will_provide", label: "I will provide custom inserts" },
        ],
      },
    ],
  },
  {
    id: "order_handling_preferences",
    modalTitle: "Order Handling Preferences",
    fields: [
      {
        key: "order_shipment_timeline",
        type: "select",
        label: "Order Shipment Timeline",
        help: "Choose how quickly orders should be processed and shipped, including whether orders should ship immediately, be delayed, or held for approval.",
        required: true,
        options: [
          { value: "ship_as_ready", label: "Ship orders as soon as they are ready without delays." },
          {
            value: "hold_specified",
            label: "Hold orders for a specified amount of time (ex. 3-hours, 12-hours, 2 days)",
          },
          {
            value: "hold_until_approved",
            label: "Hold Orders Until Approved for Shipment",
          },
        ],
      },
      {
        key: "multi_warehouse_routing",
        type: "select",
        label: "Multi-Warehouse Order Routing",
        help: "Choose how orders should be imported and routed when inventory is stored across multiple warehouse locations or fulfillment centers.",
        required: true,
        options: [
          { value: "import_all_locations", label: "Import Orders From All Warehouse Locations" },
          {
            value: "import_selected_locations",
            label: "Import Orders From Selected Warehouse Locations Only",
          },
          {
            value: "import_save_rack_only",
            label: "Import Orders Only Assigned to Save Rack Locations",
          },
        ],
      },
      {
        key: "out_of_stock_handling",
        type: "select",
        label: "Out-of-Stock Handling",
        help: "Choose how orders should be handled when one or more items are out of stock.",
        required: true,
        options: [
          { value: "hold_until_back_in_stock", label: "Hold Order Until All Items Are Back in Stock" },
          { value: "allow_partial_shipment", label: "Allow Partial Shipment of Available Items" },
          {
            value: "cancel_oos_ship_remaining",
            label: "Cancel Out-of-Stock Items and Ship Remaining Products",
          },
          { value: "require_manual_review", label: "Require Manual Review Before Processing" },
        ],
      },
      {
        key: "address_verification",
        type: "select",
        label: "Address Verification",
        help: "We may correct simple address issues such as misspelled streets, incorrect cities/states, or missing ZIP codes before shipment.",
        required: true,
        options: [
          { value: "hold_invalid", label: "Automatically Hold Orders With Invalid Addresses" },
          { value: "attempt_correction", label: "Attempt Address Correction by Account Manager" },
        ],
      },
      {
        key: "fraud_review_holds",
        type: "select",
        label: "Fraud Review Holds",
        help: "Choose how orders flagged as potentially fraudulent or high-risk should be handled before shipment.",
        required: true,
        options: [
          { value: "hold_high_risk", label: "Hold orders flagged as high-risk or potentially fraudulent." },
          { value: "ship_regardless", label: "Ship Orders Regardless of Fraud Score" },
          { value: "cancel_fraudulent", label: "Cancel Orders Marked as Fraudulent" },
        ],
      },
    ],
  },
  {
    id: "packing_slips_preferences",
    modalTitle: "Packing Slips Preferences",
    fields: [
      {
        key: "include_packing_slips",
        type: "select",
        label: "Include Packing Slips",
        required: true,
        options: [
          { value: "yes", label: "Yes" },
          { value: "no", label: "No" },
        ],
      },
      {
        key: "include_brand_logo",
        type: "select",
        label: "Include Brand Logo",
        required: true,
        options: [
          { value: "yes", label: "Yes" },
          { value: "no", label: "No" },
        ],
      },
      {
        key: "show_product_pricing",
        type: "select",
        label: "Show Product Pricing",
        required: true,
        options: [
          { value: "yes", label: "Yes" },
          { value: "no", label: "No" },
        ],
      },
      {
        key: "include_support_phone",
        type: "select",
        label: "Include Support Phone #",
        required: true,
        options: [
          { value: "yes", label: "Yes" },
          { value: "no", label: "No" },
        ],
      },
      {
        key: "include_note",
        type: "select",
        label: "Include Note",
        required: true,
        options: [
          { value: "yes", label: "Yes" },
          { value: "no", label: "No" },
        ],
      },
      {
        key: "packing_slip_note",
        type: "textarea",
        label: "Packing Slip Note",
        help: "Enter the note to print on packing slips.",
        required: true,
        showWhen: { field: "include_note", value: "yes" },
      },
    ],
  },
  {
    id: "shipping_carrier_preferences",
    modalTitle: "Shipping Carrier Preferences",
    fields: [
      {
        key: "domestic_carriers",
        type: "select",
        label: "Domestic Carriers",
        help: "Choose your preferred shipping carriers, service levels, and delivery preferences for outbound shipments.",
        required: true,
        options: [
          { value: "lowest_cost", label: "Use Lowest Cost Carrier Automatically" },
          { value: "store_requested", label: "Use Shipping Method Requested by Store" },
          { value: "usps_preferred", label: "USPS Preferred" },
          { value: "ups_preferred", label: "UPS Preferred" },
        ],
      },
      {
        key: "international_carriers",
        type: "select",
        label: "International Carriers",
        help: "Select your preferred carriers and shipping methods for international shipments.",
        required: true,
        options: [
          { value: "lowest_cost_ddu", label: "Use Lowest Cost Carrier Automatically (DDU only)" },
          { value: "lowest_cost_ddp", label: "Use Lowest Cost Carrier Automatically (DDP only)" },
          { value: "usps_globalpost", label: "USPS International Mail (GlobalPost)" },
          { value: "ups_canada_expedited", label: "UPS Ground to Canada or Expedited" },
          { value: "dhl_express", label: "DHL Express" },
        ],
      },
      {
        key: "international_customs_declaration",
        type: "select",
        label: "International Customs Declaration",
        help: "Choose how product values should be declared on international customs forms for duties, taxes, and import processing.",
        required: true,
        options: [
          { value: "retail_value", label: "Use Retail Value for Duties and Taxes" },
          { value: "custom_declared_value", label: "Use Custom Declared Value" },
        ],
      },
    ],
  },
  {
    id: "returns_handling_preferences",
    modalTitle: "Returns Handling Preferences",
    fields: [
      {
        key: "returned_items",
        type: "select",
        label: "Returned Items",
        help: "Choose how returned items should be inspected, restocked, disposed of, or handled once they arrive at the fulfillment center.",
        required: true,
        options: [
          { value: "restock_automatically", label: "Restock Returned Items Automatically" },
          { value: "dispose_non_restockable", label: "Dispose of Non-Restockable Items" },
          { value: "quarantine_before_disposal", label: "Quarantine Returned Items Before Disposal" },
          { value: "require_client_approval", label: "Require Client Approval for All Returns" },
        ],
      },
      {
        key: "returned_item_disposal",
        type: "select",
        label: "Returned Item Disposal",
        help: "Choose how to handle non-restockable or unwanted returned items, including disposal, donation, return shipping, or other recovery options.",
        required: true,
        options: [
          { value: "dispose", label: "Dispose of Items" },
          { value: "donate", label: "Donate Items to Charity" },
          { value: "ship_back", label: "Ship Items Back to Client" },
          { value: "parts_repackaging", label: "Use Disposed Items for Parts or Repackaging" },
        ],
      },
      {
        key: "photos_of_returns",
        type: "select",
        label: "Photos of Returns",
        help: "Choose whether to take photos of returned items during inspection. Additional fees may apply for return photo services.",
        required: true,
        options: [
          { value: "no_photos", label: "No Photos Required" },
          { value: "all_returns", label: "Take Photos of All Returns" },
          { value: "damaged_only", label: "Take Photos of Damaged Returns Only" },
        ],
      },
    ],
  },
  {
    id: "inventory_sync",
    modalTitle: "Inventory Sync",
    fields: [
      {
        key: "real_time_inventory_sync",
        type: "select",
        label: "Real-Time Inventory Sync",
        help: "Enable or disable real-time inventory syncing between your store, sales channels, and warehouse inventory to help prevent overselling and inventory discrepancies.",
        required: true,
        options: [
          { value: "enable_all_locations", label: "Enable Real-Time Inventory Sync (all locations)" },
          { value: "enable_save_rack_only", label: "Enable Real-Time Inventory Sync (Save Rack only)" },
          { value: "disable", label: "Disable Real-Time Inventory Sync" },
        ],
      },
    ],
  },
];

export function getPortalOnboardingSection(sectionId) {
  return PORTAL_ONBOARDING_SECTIONS.find((s) => s.id === sectionId) || null;
}

/** Fields that require admin verification checkmarks per section (must match backend registry). */
const ADMIN_VERIFICATION_FIELDS_BY_SECTION = {
  branding_information: ["brand_name"],
  order_handling_preferences: [
    "order_shipment_timeline",
    "multi_warehouse_routing",
    "out_of_stock_handling",
    "address_verification",
    "fraud_review_holds",
  ],
  packing_slips_preferences: [
    "include_packing_slips",
    "include_brand_logo",
    "show_product_pricing",
    "include_support_phone",
    "include_note",
    "packing_slip_note",
  ],
  shipping_carrier_preferences: [
    "domestic_carriers",
    "international_carriers",
    "international_customs_declaration",
  ],
  returns_handling_preferences: [
    "returned_items",
    "returned_item_disposal",
    "photos_of_returns",
  ],
  inventory_sync: ["real_time_inventory_sync"],
};

export const PORTAL_ONBOARDING_ADMIN_FIELD_VERIFICATION_SECTION_IDS = Object.keys(
  ADMIN_VERIFICATION_FIELDS_BY_SECTION,
);

export function sectionUsesAdminFieldVerification(sectionId) {
  return Object.prototype.hasOwnProperty.call(ADMIN_VERIFICATION_FIELDS_BY_SECTION, sectionId);
}

export function fieldRequiresAdminVerification(sectionId, fieldKey) {
  const keys = ADMIN_VERIFICATION_FIELDS_BY_SECTION[sectionId];
  return Array.isArray(keys) && keys.includes(fieldKey);
}

function fieldVisibleForForm(field, form) {
  const rule = field?.showWhen;
  if (!rule || typeof rule !== "object") return true;
  return String(form[rule.field] ?? "") === String(rule.value);
}

export function visibleAdminVerificationFields(sectionId, form) {
  const section = getPortalOnboardingSection(sectionId);
  if (!section) return [];

  return (section.fields || [])
    .filter((field) => fieldRequiresAdminVerification(sectionId, field.key))
    .filter((field) => fieldVisibleForForm(field, form))
    .map((field) => field.key);
}

/** Placeholder until Tutorials section URLs are available. */
export function fieldTutorialUrl(_sectionId, _fieldKey) {
  return "#";
}

/** Map task.icon from API to PORTAL_MATERIAL_ICON keys */
export const PORTAL_ONBOARDING_TASK_ICON_KEYS = {
  account: "account",
  chat: "chat",
  billing: "payments",
  palette: "palette",
  tune: "tune",
  shelves: "shelves",
  location: "location",
  shield: "shield",
  description: "description",
  local_shipping: "localShipping",
  assignment_return: "assignmentReturn",
  sync: "sync",
  fulfillment_agreement: "description",
};
