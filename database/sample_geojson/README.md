# Sample planning layers

These files are synthetic GeoJSON fixtures for testing the Spatial Analysis upload and intersection workflow:

- `demo_zoning.geojson` — choose **Zoning**
- `demo_hazard.geojson` — choose **Hazard**
- `demo_waterbody.geojson` — choose **Waterbody / protected area**

In the MPDO workspace, open **Spatial Analysis**, enter a layer name such as `DEMO ONLY - zoning test`, choose the matching category, select the GeoJSON file, and save it. The sample polygons intentionally cover the same small illustrative area so they can exercise intersection checks.

**These are not official municipal datasets.** The geometries, classifications, and boundaries are invented placeholders. Do not use them for permitting, land-use, hazard, waterbody, or protected-area decisions, and remove them from any production database after testing.

Coordinates use GeoJSON longitude/latitude order (WGS 84). Each file is a small `FeatureCollection` containing a closed `Polygon` ring supported by the application.
