/** Must match {@see \App\Support\JobPositions::allowed()} */
export const JOB_POSITION_VALUES = [
  "Picker & Packer",
  "Receiving",
  "Inventory",
  "Account Manager",
  "Account Sr. Manager",
  "Accounting",
  "Operations Manager",
];

export const JOB_POSITION_OPTIONS = [
  { value: "", label: "Not Specified" },
  ...JOB_POSITION_VALUES.map((v) => ({ value: v, label: v })),
];
