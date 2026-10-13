"""Render an STL mesh with an optional planar surface finish."""

import argparse
import sys
from pathlib import Path

import bpy
import bmesh


def parse_args():
    parser = argparse.ArgumentParser()
    parser.add_argument("--stl", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--surface-level", type=float, default=0.0)
    parser.add_argument("--surface-material", default="SurfaceFinish")
    parser.add_argument("--base-material", default="BaseMaterial")
    return parser.parse_args(sys.argv[sys.argv.index("--") + 1 :])


def import_stl(path: Path):
    before = set(bpy.data.objects)
    bpy.ops.wm.stl_import(filepath=str(path))
    imported = [obj for obj in bpy.data.objects if obj not in before and obj.type == "MESH"]
    if len(imported) != 1:
        raise RuntimeError(f"Expected one imported mesh, got {len(imported)}")
    return imported[0]


def split_mesh_at_level(obj, level: float) -> None:
    bm = bmesh.new()
    bm.from_mesh(obj.data)
    bmesh.ops.remove_doubles(bm, verts=list(bm.verts), dist=1e-6)
    geometry = list(bm.verts) + list(bm.edges) + list(bm.faces)
    bmesh.ops.bisect_plane(
        bm,
        geom=geometry,
        dist=1e-6,
        plane_co=(0.0, 0.0, level),
        plane_no=(0.0, 0.0, 1.0),
        clear_inner=False,
        clear_outer=False,
    )
    bm.to_mesh(obj.data)
    bm.free()
    obj.data.update()


def assign_material_regions(obj, level: float, surface_name: str, base_name: str) -> None:
    surface = bpy.data.materials.get(surface_name)
    base = bpy.data.materials.get(base_name)
    if surface is None or base is None:
        raise RuntimeError("Create the requested materials in the active Blender scene first")
    obj.data.materials.clear()
    obj.data.materials.append(surface)
    obj.data.materials.append(base)
    for polygon in obj.data.polygons:
        vertex_levels = [obj.data.vertices[index].co.z for index in polygon.vertices]
        polygon.material_index = 1 if max(vertex_levels) <= level + 1e-6 else 0


def main() -> None:
    args = parse_args()
    args.output.mkdir(parents=True, exist_ok=True)
    obj = import_stl(args.stl)
    split_mesh_at_level(obj, args.surface_level)
    assign_material_regions(obj, args.surface_level, args.surface_material, args.base_material)
    print(f"Prepared {obj.name}; configure a licensed scene to produce renders.")


if __name__ == "__main__":
    main()
