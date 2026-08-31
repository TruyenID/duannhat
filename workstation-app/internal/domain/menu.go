package domain

type MenuItem struct {
	ID           string `json:"id"`
	Name         string `json:"name"`
	NameJa       string `json:"name_ja"`
	Category     string `json:"category"`
	Price        int    `json:"price"`
	PrinterGroup string `json:"printer_group"`
	IsActive     bool   `json:"is_active"`
}
