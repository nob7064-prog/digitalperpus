<?php
/**
 * Simple Excel Export Library
 * Generates Excel-compatible XML files with proper table formatting
 */

class ExcelExport {
    private $filename;
    private $data;
    private $headers;
    private $title;
    private $subtitle;

    public function __construct($filename = 'export') {
        $this->filename = $filename;
        $this->data = [];
        $this->headers = [];
    }

    public function setTitle($title) {
        $this->title = $title;
    }

    public function setSubtitle($subtitle) {
        $this->subtitle = $subtitle;
    }

    public function addSheet($sheetName, $headers, $data, $summary = null) {
        $this->data[$sheetName] = [
            'headers' => $headers,
            'data' => $data,
            'summary' => $summary
        ];
    }

    public function addSummarySheet($sheetName, $summaryData) {
        $this->data[$sheetName] = [
            'headers' => ['Item', 'Value'],
            'data' => $summaryData,
            'summary' => null,
            'is_summary' => true
        ];
    }

    private function generateXML() {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
        $xml .= ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
        $xml .= ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
        $xml .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
        $xml .= ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
        $xml .= ' <ExcelWorkbook xmlns="urn:schemas-microsoft-com:office:excel">' . "\n";
        $xml .= '  <WindowHeight>9000</WindowHeight>' . "\n";
        $xml .= '  <WindowWidth>16000</WindowWidth>' . "\n";
        $xml .= '  <WindowTopX>0</WindowTopX>' . "\n";
        $xml .= '  <WindowTopY>0</WindowTopY>' . "\n";
        $xml .= '  <ProtectStructure>False</ProtectStructure>' . "\n";
        $xml .= '  <ProtectWindows>False</ProtectWindows>' . "\n";
        $xml .= ' </ExcelWorkbook>' . "\n";

        // Styles
        $xml .= ' <Styles>' . "\n";
        $xml .= '  <Style ss:ID="Default" ss:Name="Normal">' . "\n";
        $xml .= '   <Alignment ss:Vertical="Bottom"/>' . "\n";
        $xml .= '   <Borders/>' . "\n";
        $xml .= '   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Color="#000000"/>' . "\n";
        $xml .= '   <Interior/>' . "\n";
        $xml .= '   <NumberFormat/>' . "\n";
        $xml .= '   <Protection/>' . "\n";
        $xml .= '  </Style>' . "\n";
        $xml .= '  <Style ss:ID="Header">' . "\n";
        $xml .= '   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . "\n";
        $xml .= '   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="12" ss:Color="#FFFFFF" ss:Bold="1"/>' . "\n";
        $xml .= '   <Interior ss:Color="#4F81BD" ss:Pattern="Solid"/>' . "\n";
        $xml .= '  </Style>' . "\n";
        $xml .= '  <Style ss:ID="Title">' . "\n";
        $xml .= '   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . "\n";
        $xml .= '   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="16" ss:Color="#1F497D" ss:Bold="1"/>' . "\n";
        $xml .= '  </Style>' . "\n";
        $xml .= '  <Style ss:ID="Subtitle">' . "\n";
        $xml .= '   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' . "\n";
        $xml .= '   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="12" ss:Color="#666666"/>' . "\n";
        $xml .= '  </Style>' . "\n";
        $xml .= '  <Style ss:ID="SummaryHeader">' . "\n";
        $xml .= '   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>' . "\n";
        $xml .= '   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Color="#FFFFFF" ss:Bold="1"/>' . "\n";
        $xml .= '   <Interior ss:Color="#9BBB59" ss:Pattern="Solid"/>' . "\n";
        $xml .= '  </Style>' . "\n";
        $xml .= '  <Style ss:ID="Data">' . "\n";
        $xml .= '   <Borders>' . "\n";
        $xml .= '    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
        $xml .= '    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
        $xml .= '    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
        $xml .= '    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
        $xml .= '   </Borders>' . "\n";
        $xml .= '  </Style>' . "\n";
        $xml .= '  <Style ss:ID="Number">' . "\n";
        $xml .= '   <Borders>' . "\n";
        $xml .= '    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
        $xml .= '    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
        $xml .= '    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
        $xml .= '    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
        $xml .= '   </Borders>' . "\n";
        $xml .= '   <NumberFormat ss:Format="#,##0"/>' . "\n";
        $xml .= '  </Style>' . "\n";
        $xml .= '  <Style ss:ID="Currency">' . "\n";
        $xml .= '   <Borders>' . "\n";
        $xml .= '    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
        $xml .= '    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
        $xml .= '    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
        $xml .= '    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
        $xml .= '   </Borders>' . "\n";
        $xml .= '   <NumberFormat ss:Format="#,##0.00"/>' . "\n";
        $xml .= '  </Style>' . "\n";
        $xml .= ' </Styles>' . "\n";

        $sheetIndex = 0;
        foreach ($this->data as $sheetName => $sheetData) {
            $xml .= ' <Worksheet ss:Name="' . htmlspecialchars($sheetName) . '">' . "\n";
            $xml .= '  <Table ss:ExpandedColumnCount="' . count($sheetData['headers']) . '" ss:ExpandedRowCount="' . (count($sheetData['data']) + 10) . '" x:FullColumns="1" x:FullRows="1" ss:DefaultRowHeight="15">' . "\n";

            // Set column widths
            foreach ($sheetData['headers'] as $header) {
                $width = strlen($header) * 1.2 + 5;
                $xml .= '   <Column ss:AutoFitWidth="0" ss:Width="' . $width . '"/>' . "\n";
            }

            $rowIndex = 0;

            // Title row (if set)
            if ($this->title && $sheetIndex === 0) {
                $xml .= '   <Row ss:AutoFitHeight="0" ss:Height="25">' . "\n";
                $xml .= '    <Cell ss:MergeAcross="' . (count($sheetData['headers']) - 1) . '" ss:StyleID="Title"><Data ss:Type="String">' . htmlspecialchars($this->title) . '</Data></Cell>' . "\n";
                $xml .= '   </Row>' . "\n";
                $rowIndex++;
            }

            // Subtitle row (if set)
            if ($this->subtitle && $sheetIndex === 0) {
                $xml .= '   <Row ss:AutoFitHeight="0" ss:Height="20">' . "\n";
                $xml .= '    <Cell ss:MergeAcross="' . (count($sheetData['headers']) - 1) . '" ss:StyleID="Subtitle"><Data ss:Type="String">' . htmlspecialchars($this->subtitle) . '</Data></Cell>' . "\n";
                $xml .= '   </Row>' . "\n";
                $rowIndex++;
            }

            // Empty row for spacing
            if (($this->title || $this->subtitle) && $sheetIndex === 0) {
                $xml .= '   <Row ss:AutoFitHeight="0" ss:Height="10">' . "\n";
                $xml .= '    <Cell ss:MergeAcross="' . (count($sheetData['headers']) - 1) . '"><Data ss:Type="String"></Data></Cell>' . "\n";
                $xml .= '   </Row>' . "\n";
                $rowIndex++;
            }

            // Headers
            $xml .= '   <Row ss:AutoFitHeight="0" ss:Height="20">' . "\n";
            foreach ($sheetData['headers'] as $header) {
                $style = isset($sheetData['is_summary']) && $sheetData['is_summary'] ? 'SummaryHeader' : 'Header';
                $xml .= '    <Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . htmlspecialchars($header) . '</Data></Cell>' . "\n";
            }
            $xml .= '   </Row>' . "\n";

            // Data rows
            foreach ($sheetData['data'] as $row) {
                $xml .= '   <Row ss:AutoFitHeight="0">' . "\n";
                foreach ($row as $cell) {
                    $cellValue = $cell;
                    $cellType = 'String';
                    $style = 'Data';

                    // Determine cell type and style
                    if (is_numeric($cellValue)) {
                        if (strpos($cellValue, '.') !== false || strpos($cellValue, ',') !== false) {
                            $cellType = 'Number';
                            $style = 'Currency';
                            $cellValue = str_replace(',', '', $cellValue);
                        } else {
                            $cellType = 'Number';
                            $style = 'Number';
                        }
                    }

                    $xml .= '    <Cell ss:StyleID="' . $style . '"><Data ss:Type="' . $cellType . '">' . htmlspecialchars($cellValue) . '</Data></Cell>' . "\n";
                }
                $xml .= '   </Row>' . "\n";
            }

            // Summary section (if provided)
            if ($sheetData['summary']) {
                $xml .= '   <Row ss:AutoFitHeight="0" ss:Height="10">' . "\n";
                $xml .= '    <Cell ss:MergeAcross="' . (count($sheetData['headers']) - 1) . '"><Data ss:Type="String"></Data></Cell>' . "\n";
                $xml .= '   </Row>' . "\n";

                $xml .= '   <Row ss:AutoFitHeight="0" ss:Height="20">' . "\n";
                $xml .= '    <Cell ss:MergeAcross="' . (count($sheetData['headers']) - 1) . '" ss:StyleID="SummaryHeader"><Data ss:Type="String">SUMMARY</Data></Cell>' . "\n";
                $xml .= '   </Row>' . "\n";

                foreach ($sheetData['summary'] as $summaryItem) {
                    $xml .= '   <Row ss:AutoFitHeight="0">' . "\n";
                    $xml .= '    <Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars($summaryItem[0]) . '</Data></Cell>' . "\n";
                    $xml .= '    <Cell ss:StyleID="Data"><Data ss:Type="String">' . htmlspecialchars($summaryItem[1]) . '</Data></Cell>' . "\n";
                    for ($i = 2; $i < count($sheetData['headers']); $i++) {
                        $xml .= '    <Cell ss:StyleID="Data"><Data ss:Type="String"></Data></Cell>' . "\n";
                    }
                    $xml .= '   </Row>' . "\n";
                }
            }

            $xml .= '  </Table>' . "\n";
            $xml .= '  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">' . "\n";
            $xml .= '   <PageSetup>' . "\n";
            $xml .= '    <Layout x:Orientation="Landscape"/>' . "\n";
            $xml .= '    <Header x:Margin="0.3"/>' . "\n";
            $xml .= '    <Footer x:Margin="0.3"/>' . "\n";
            $xml .= '    <PageMargins x:Bottom="0.75" x:Left="0.7" x:Right="0.7" x:Top="0.75"/>' . "\n";
            $xml .= '   </PageSetup>' . "\n";
            $xml .= '   <FitToPage/>' . "\n";
            $xml .= '   <Print>' . "\n";
            $xml .= '    <FitHeight>0</FitHeight>' . "\n";
            $xml .= '    <ValidPrinterInfo/>' . "\n";
            $xml .= '    <PaperSizeIndex>9</PaperSizeIndex>' . "\n";
            $xml .= '    <HorizontalResolution>600</HorizontalResolution>' . "\n";
            $xml .= '    <VerticalResolution>600</VerticalResolution>' . "\n";
            $xml .= '   </Print>' . "\n";
            $xml .= '   <Zoom>90</Zoom>' . "\n";
            $xml .= '   <Selected/>' . "\n";
            $xml .= '   <FreezePanes/>' . "\n";
            $xml .= '   <FrozenNoSplit/>' . "\n";
            $xml .= '   <SplitHorizontal>3</SplitHorizontal>' . "\n";
            $xml .= '   <TopRowBottomPane>3</TopRowBottomPane>' . "\n";
            $xml .= '   <ActivePane>2</ActivePane>' . "\n";
            $xml .= '   <Panes>' . "\n";
            $xml .= '    <Pane>' . "\n";
            $xml .= '     <Number>3</Number>' . "\n";
            $xml .= '    </Pane>' . "\n";
            $xml .= '    <Pane>' . "\n";
            $xml .= '     <Number>2</Number>' . "\n";
            $xml .= '     <ActiveRow>3</ActiveRow>' . "\n";
            $xml .= '    </Pane>' . "\n";
            $xml .= '   </Panes>' . "\n";
            $xml .= '   <ProtectObjects>False</ProtectObjects>' . "\n";
            $xml .= '   <ProtectScenarios>False</ProtectScenarios>' . "\n";
            $xml .= '  </WorksheetOptions>' . "\n";
            $xml .= ' </Worksheet>' . "\n";

            $sheetIndex++;
        }

        $xml .= '</Workbook>';

        return $xml;
    }

    public function download() {
        $xml = $this->generateXML();

        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $this->filename . '.xls"');
        header('Cache-Control: max-age=0');

        echo $xml;
        exit;
    }
}

// Helper function to create Excel export
function createExcelExport($filename, $title = '', $subtitle = '') {
    $excel = new ExcelExport($filename);
    if ($title) $excel->setTitle($title);
    if ($subtitle) $excel->setSubtitle($subtitle);
    return $excel;
}
?>
